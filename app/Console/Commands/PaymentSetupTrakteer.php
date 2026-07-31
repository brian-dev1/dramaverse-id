<?php

namespace App\Console\Commands;

use App\Enums\PaymentDriver;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\DB;

use Illuminate\Console\Command;

/**
 * Memasang provider Trakteer dalam satu perintah.
 *
 * ## Kenapa ini ada
 *
 * Memasangnya lewat panel admin memerlukan enam langkah yang harus benar
 * semua: nama, slug, driver, kredensial, aktifkan, jadikan default. Satu saja
 * meleset — dan yang paling sering meleset adalah **slug** — endpoint callback
 * menjawab `404 Provider tidak dikenal`, gateway menganggapnya gagal, dan
 * pembayaran yang sah tidak pernah mengaktifkan apa pun.
 *
 * Kegagalannya tidak menyebutkan apa yang salah, hanya bahwa sesuatu salah.
 *
 * Perintah ini menetapkan slug-nya secara pasti (`trakteer`), sehingga URL
 * callback-nya selalu sama dan bisa disalin apa adanya ke dashboard Trakteer.
 *
 *     php artisan payment:setup-trakteer \
 *       --token=RAHASIA \
 *       --page=https://trakteer.id/DRAMAVERSEID/tip \
 *       --units="Kopi=2000"
 */
class PaymentSetupTrakteer extends Command
{
    /** Slug ditetapkan, bukan diturunkan dari nama. Lihat docblock kelas. */
    private const SLUG = 'trakteer';

    protected $signature = 'payment:setup-trakteer
                            {--token= : Webhook token dari dashboard Trakteer}
                            {--page= : URL halaman Trakteer}
                            {--units= : Daftar unit, contoh "Kopi=2000;Cendol=5000"}
                            {--name=Trakteer : Nama yang tampil ke pengguna}';

    protected $description = 'Pasang atau perbarui provider pembayaran Trakteer';

    public function handle(): int
    {
        $token = $this->option('token') ?: $this->secret('Webhook token dari dashboard Trakteer');

        $page = $this->option('page') ?: $this->ask('URL halaman Trakteer');

        if (blank($token) || blank($page)) {
            $this->components->error('Token dan URL halaman wajib diisi.');

            return self::FAILURE;
        }

        // Titik koma diterima sebagai pemisah supaya seluruh daftar muat di
        // satu argumen baris perintah; disimpan sebagai baris terpisah karena
        // itu bentuk yang dibaca PaymentProvider::units().
        $units = str_replace(';', "\n", (string) $this->option('units'));

        $provider = DB::transaction(function () use ($token, $page, $units) {

            // withTrashed(): provider yang pernah dihapus masih memegang
            // slug-nya, dan membuat yang baru akan bertabrakan dengan unique
            // index `(slug, deleted_at)`.
            $provider = PaymentProvider::withTrashed()
                ->where('slug', self::SLUG)
                ->first() ?? new PaymentProvider(['slug' => self::SLUG]);

            if ($provider->trashed()) {
                $provider->restore();
            }

            $kredensial = ($provider->credentials ?? []) + [];

            $kredensial['webhook_token'] = trim($token);
            $kredensial['page_url']      = rtrim(trim($page), '/');

            if (filled($units)) {
                $kredensial['units'] = trim($units);
            }

            $provider->fill([
                'name'        => (string) $this->option('name'),
                'slug'        => self::SLUG,
                'driver'      => PaymentDriver::TRAKTEER,
                'credentials' => $kredensial,
                'mode'        => 'live',
                'is_active'   => true,
                'instruction' => 'Bayar lewat Trakteer. WAJIB menyertakan nomor '
                    .'tagihan di kolom pesan — tanpa itu pembayaran tidak '
                    .'tersambung ke tagihan Anda.',
            ])->save();

            // Tepat satu default. Dibersihkan lebih dulu supaya tidak ada dua.
            PaymentProvider::where('id', '!=', $provider->id)->update(['is_default' => false]);

            $provider->forceFill(['is_default' => true])->save();

            return $provider->refresh();
        });

        /*
        |----------------------------------------------------------------------
        | Laporkan, lalu buktikan
        |----------------------------------------------------------------------
        */

        $this->newLine();
        $this->components->info('Provider Trakteer terpasang.');

        $this->components->twoColumnDetail('Slug', $provider->slug);
        $this->components->twoColumnDetail('Halaman', $provider->credential('page_url'));
        $this->components->twoColumnDetail(
            'Unit',
            $provider->units() === []
                ? 'belum diisi (opsional)'
                : collect($provider->units())
                    ->map(fn (array $u) => $u['nama'].' Rp '.number_format($u['harga'], 0, ',', '.'))
                    ->implode(', ')
        );

        $this->newLine();
        $this->components->info('Salin URL ini ke dashboard Trakteer:');
        $this->line('  <options=bold>'.url('/payment/callback/'.$provider->slug).'</>');

        if ($alasan = $provider->blocker()) {
            $this->newLine();
            $this->components->error('Masih belum siap: '.$alasan);

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Provider siap menerima pembayaran.');

        $this->components->bulletList([
            'Nyalakan toggle Webhook di dashboard Trakteer.',
            'Tempel URL di atas, simpan.',
            'Tekan "Send Webhook Test" — jawabannya harus ok:true.',
            'Buktikan alurnya: php artisan payment:webhook-test <nomor-tagihan>',
        ]);

        return self::SUCCESS;
    }
}
