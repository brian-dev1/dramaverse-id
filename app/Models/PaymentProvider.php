<?php

namespace App\Models;

use App\Enums\PaymentDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu provider pembayaran yang terpasang.
 *
 * `credentials` di-cast `encrypted:array` — nilainya terenkripsi di kolom dan
 * hanya terbaca sebagai array di dalam aplikasi. Server key gateway setara
 * kunci brankas: siapa pun yang memilikinya bisa membuat transaksi atas nama
 * kita.
 */
class PaymentProvider extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'credentials',
        'mode',
        'is_active',
        'is_default',
        'sort_order',
        'fee_percent',
        'fee_flat',
        'instruction',
        'qris_image_path',
    ];

    protected $casts = [
        'driver'      => PaymentDriver::class,
        'credentials' => 'encrypted:array',
        'is_active'   => 'boolean',
        'is_default'  => 'boolean',
        'sort_order'  => 'integer',
        'fee_percent' => 'decimal:2',
        'fee_flat'    => 'decimal:2',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Satu nilai kredensial. */
    public function credential(string $key): ?string
    {
        $nilai = ($this->credentials ?? [])[$key] ?? null;

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /** Field wajib yang masih kosong. */
    public function missingFields(): array
    {
        $kurang = [];

        foreach ($this->driver->requiredFields() as $field => $label) {
            if ($this->credential($field) === null) {
                $kurang[] = $field;
            }
        }

        return $kurang;
    }

    /**
     * Siap menerima pembayaran.
     *
     * Aktif saja tidak cukup: driver yang belum selesai dan kredensial yang
     * belum lengkap sama-sama menghasilkan kegagalan di tengah checkout,
     * yaitu tempat paling buruk untuk gagal.
     */
    public function isUsable(): bool
    {
        return $this->is_active
            && $this->driver->isImplemented()
            && $this->missingFields() === [];
    }

    /** Alasan tidak bisa dipakai, atau null bila bisa. */
    public function blocker(): ?string
    {
        if (! $this->driver->isImplemented()) {
            return "Driver {$this->driver->label()} masih kerangka — alur callback dan "
                .'tanda tangannya belum pernah diuji dengan akun sungguhan.';
        }

        if ($kurang = $this->missingFields()) {
            return 'Kredensial belum lengkap: '.implode(', ', $kurang).'.';
        }

        if (! $this->is_active) {
            return 'Provider berstatus nonaktif.';
        }

        return null;
    }

    /** Biaya layanan untuk nominal tertentu. */
    public function feeFor(float $subtotal): float
    {
        return round($subtotal * ((float) $this->fee_percent / 100) + (float) $this->fee_flat, 2);
    }

    public function isSandbox(): bool
    {
        return $this->mode !== 'live';
    }

    /*
    |--------------------------------------------------------------------------
    | Satuan
    |--------------------------------------------------------------------------
    */

    /**
     * Daftar unit beserta harganya, untuk driver yang menjual per satuan.
     *
     * Dibaca dari kredensial `units`, satu baris satu unit:
     *
     *     Cendol=5000
     *     Kopi=2000
     *     Boba=10000
     *
     * Trakteer mengizinkan kreator membuat beberapa unit dengan harga
     * berbeda, jadi satu pasang nama-harga tidak cukup.
     *
     * **Ini hanya untuk tampilan.** Pencocokan pembayaran membaca nominal
     * dari payload webhook, apa pun unit yang dipakai pendukung — daftar yang
     * basi karena harga di Trakteer berubah tidak pernah membuat pembayaran
     * yang sah jadi ditolak. Itu pemisahan yang disengaja.
     *
     * Baris yang bentuknya salah dilewati, bukan menggagalkan seluruh daftar.
     *
     * @return array<int,array{nama:string, harga:float}> urut harga menaik
     */
    public function units(): array
    {
        $mentah = $this->credential('units');

        if ($mentah === null) {
            return [];
        }

        $hasil = [];

        foreach (preg_split('/\r\n|\r|\n/', $mentah) ?: [] as $baris) {

            $baris = trim($baris);

            if ($baris === '' || ! str_contains($baris, '=')) {
                continue;
            }

            [$nama, $harga] = array_map('trim', explode('=', $baris, 2));

            // Titik dan koma sebagai pemisah ribuan dibuang: orang menulis
            // "5.000" sesering "5000", dan menolaknya hanya membuat daftar
            // gagal tanpa sebab yang terlihat.
            $harga = (float) str_replace(['.', ',', ' ', 'Rp'], '', $harga);

            if ($nama === '' || $harga <= 0) {
                continue;
            }

            $hasil[] = ['nama' => $nama, 'harga' => $harga];
        }

        usort($hasil, fn (array $a, array $b) => $a['harga'] <=> $b['harga']);

        return $hasil;
    }

    /**
     * Saran jumlah unit untuk sebuah nominal.
     *
     * Dibulatkan KE ATAS. Mengirim kurang satu unit berarti tagihan tidak
     * pernah lunas, dan itu jauh lebih menjengkelkan daripada kelebihan
     * beberapa ratus rupiah.
     *
     * `pas` menandai unit yang membagi habis — itu yang sebaiknya dipilih
     * pengguna, dan panel menampilkannya lebih dulu.
     *
     * @return array<int,array{nama:string, harga:float, jumlah:int, total:float, pas:bool}>
     */
    public function unitSuggestions(float $nominal): array
    {
        if ($nominal <= 0) {
            return [];
        }

        $saran = [];

        foreach ($this->units() as $unit) {

            $jumlah = (int) ceil($nominal / $unit['harga']);

            $total = $jumlah * $unit['harga'];

            $saran[] = [
                'nama'   => $unit['nama'],
                'harga'  => $unit['harga'],
                'jumlah' => $jumlah,
                'total'  => $total,
                'pas'    => abs($total - $nominal) < 0.01,
            ];
        }

        // Yang pas lebih dulu, lalu yang kelebihannya paling kecil.
        usort($saran, function (array $a, array $b) use ($nominal) {

            if ($a['pas'] !== $b['pas']) {
                return $a['pas'] ? -1 : 1;
            }

            return ($a['total'] - $nominal) <=> ($b['total'] - $nominal);
        });

        return $saran;
    }
}
