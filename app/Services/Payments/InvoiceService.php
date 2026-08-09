<?php

namespace App\Services\Payments;

use App\Support\Concerns\LogsPaymentEvents;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Models\PaymentProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pembuatan dan penutupan tagihan.
 *
 * Dipisahkan dari `CheckoutService` karena invoice punya alasan berubah
 * sendiri: kedaluwarsa, dibatalkan admin, atau dilunasi. Menaruhnya bersama
 * alur checkout berarti setiap perubahan pada salah satunya menyentuh yang
 * lain.
 */
class InvoiceService
{
    use LogsPaymentEvents;

    public function __construct(
        protected PaymentGatewayManager $gateways
    ) {
    }

    /**
     * Buat tagihan baru untuk satu paket.
     *
     * Nama, durasi, dan harga paket DISALIN ke invoice. Harga paket bisa
     * berubah dan paketnya bisa dihapus; invoice lama harus tetap menunjukkan
     * apa yang benar-benar dibeli, bukan keadaan paket hari ini.
     */
    public function create(User $user, MembershipPlan $plan, PaymentProvider $provider): Invoice
    {
        $subtotal = (float) $plan->price;

        $fee = $provider->feeFor($subtotal);

        $invoice = Invoice::create([
            'number'             => $this->generateNumber(),
            'user_id'            => $user->id,
            'membership_plan_id' => $plan->id,
            'plan_name'          => $plan->name,
            'plan_duration'      => (int) $plan->duration,
            'subtotal'           => $subtotal,
            'fee'                => $fee,
            'total'              => $subtotal + $fee,
            'currency'           => config('payment.currency', 'IDR'),
            'status'             => PaymentStatus::PENDING,
            'due_at'             => now()->addMinutes((int) config('payment.invoice_ttl', 1440)),
        ]);

        $this->log('info', 'invoice.created', [
            'invoice'  => $invoice->number,
            'user_id'  => $user->id,
            'plan'     => $plan->slug ?? $plan->id,
            'total'    => $invoice->total,
            'provider' => $provider->slug,
        ]);

        return $invoice;
    }

    /**
     * Nomor tagihan yang dilihat manusia.
     *
     * Bentuknya `INV-YYYYMMDD-XXXXXX`. Bagian acaknya bukan hiasan: nomor urut
     * membuat siapa pun bisa menebak nomor tagihan orang lain dan membukanya,
     * dan halaman tagihan memuat nama serta paket yang dibeli.
     *
     * Perulangannya menjaga tabrakan yang sangat jarang tetap ditangani. Enam
     * karakter dari 32 kemungkinan memberi sekitar satu miliar kombinasi per
     * hari; unique index di kolomnya adalah penjagaan terakhir kalau tetap
     * bertabrakan.
     */
    public function generateNumber(): string
    {
        for ($i = 0; $i < 5; $i++) {

            $nomor = 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

            if (! Invoice::where('number', $nomor)->exists()) {
                return $nomor;
            }
        }

        // Lima kali bertabrakan berarti ada yang tidak beres dengan
        // pembangkit acaknya. Timestamp mikrodetik pasti unik.
        return 'INV-'.now()->format('Ymd').'-'.strtoupper(base_convert((string) hrtime(true), 10, 36));
    }

    /**
     * Tandai lunas.
     *
     * Dipanggil hanya dari `PaymentCallbackService`, di dalam transaction yang
     * sama dengan pengubahan transaksinya — supaya tidak pernah ada keadaan
     * "transaksi lunas tapi invoice masih menunggu".
     */
    public function markPaid(Invoice $invoice): Invoice
    {
        $invoice->forceFill([
            'status'  => PaymentStatus::PAID,
            'paid_at' => now(),
        ])->save();

        $this->log('info', 'invoice.paid', [
            'invoice' => $invoice->number,
            'total'   => $invoice->total,
        ]);

        // Komisi affiliate. Satu-satunya tempat komisi lahir, sehingga semua
        // jalur pembayaran — gateway otomatis maupun ACC manual — melewatinya.
        // Kegagalan di dalamnya sudah ditangkap sendiri: pembayaran yang sah
        // tidak boleh batal hanya karena perhitungan bonus bermasalah.
        app(\App\Services\ReferralService::class)->awardFor($invoice);

        return $invoice;
    }

    /** Batalkan tagihan yang belum dibayar. */
    public function cancel(Invoice $invoice, string $alasan = 'Dibatalkan.'): Invoice
    {
        if ($invoice->status !== PaymentStatus::PENDING) {
            return $invoice;
        }

        $invoice->forceFill([
            'status'       => PaymentStatus::CANCELLED,
            'cancelled_at' => now(),
            'note'         => $alasan,
        ])->save();

        $this->log('info', 'invoice.cancelled', [
            'invoice' => $invoice->number,
            'alasan'  => $alasan,
        ]);

        return $invoice;
    }

    /**
     * Kedaluwarsakan tagihan yang lewat jatuh tempo.
     *
     * Dijalankan scheduler. Tagihan menggantung selamanya membuat statistik
     * pendapatan tidak bisa dipercaya, dan membuat pengguna melihat tombol
     * "bayar" untuk harga yang mungkin sudah berubah.
     *
     * @return int jumlah yang dikedaluwarsakan
     */
    public function expireOverdue(int $limit = 500): int
    {
        $jumlah = 0;

        Invoice::query()
            ->unpaid()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->limit($limit)
            ->get()
            ->each(function (Invoice $invoice) use (&$jumlah) {

                DB::transaction(function () use ($invoice, &$jumlah) {

                    $invoice->forceFill(['status' => PaymentStatus::EXPIRED])->save();

                    // Transaksi yang masih menunggu ikut ditutup. Membiarkannya
                    // PENDING berarti scheduler verifikasi akan terus
                    // menanyakannya ke provider selamanya.
                    $invoice->transactions()
                        ->where('status', PaymentStatus::PENDING->value)
                        ->update(['status' => PaymentStatus::EXPIRED->value]);

                    $jumlah++;
                });
            });

        if ($jumlah > 0) {
            $this->log('info', 'invoice.expired', ['jumlah' => $jumlah]);
        }

        return $jumlah;
    }

    /**
     * Batalkan tagihan yang belum menerima transaksi apa pun sekian lama.
     *
     * Beda dengan `expireOverdue()`: itu menunggu `due_at` penuh (bawaannya
     * 24 jam). Ini menutup tagihan yang jelas-jelas ditinggalkan jauh lebih
     * cepat — `paid_amount` masih nol berarti tidak sepeser pun pernah masuk,
     * beda dengan tagihan yang sedang dicicil dan wajar makan waktu.
     *
     * Dipanggil dari `payment:auto stale`. Pemanggilnya yang bertanggung jawab
     * membatalkan langganan PENDING terkait dan menghapus pesan Telegram-nya —
     * keduanya bukan urusan invoice, sama seperti kenapa kelas ini dipisah
     * dari `CheckoutService` sejak awal.
     *
     * @return \Illuminate\Support\Collection<int,Invoice> tagihan yang baru dibatalkan
     */
    public function expireStale(?int $menit = null, int $limit = 500): \Illuminate\Support\Collection
    {
        $menit ??= (int) config('payment.guard.stale_after', 120);

        $batas = now()->subMinutes(max(1, $menit));

        $basi = Invoice::query()
            ->unpaid()
            ->where('paid_amount', '<=', 0)
            ->where('created_at', '<=', $batas)
            ->limit($limit)
            ->get();

        $basi->each(function (Invoice $invoice) use ($menit) {

            DB::transaction(function () use ($invoice, $menit) {

                $this->cancel(
                    $invoice,
                    "Dibatalkan otomatis: tidak ada transaksi dalam {$menit} menit."
                );

                $invoice->transactions()
                    ->where('status', PaymentStatus::PENDING->value)
                    ->update(['status' => PaymentStatus::CANCELLED->value]);
            });
        });

        if ($basi->isNotEmpty()) {
            $this->log('info', 'invoice.stale_cancelled', ['jumlah' => $basi->count()]);
        }

        return $basi;
    }

}