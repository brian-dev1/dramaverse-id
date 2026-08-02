<?php

namespace App\Console\Commands;

use App\Jobs\VerifyPaymentTransaction;
use App\Models\PaymentTransaction;
use App\Services\Membership\MembershipService;
use App\Services\Payments\InvoiceService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Perawatan otomatis pembayaran dan membership.
 *
 *   php artisan payment:auto verify    tanyakan transaksi yang masih menunggu
 *   php artisan payment:auto expire    kedaluwarsakan tagihan & langganan
 *   php artisan payment:auto stale     batalkan tagihan tanpa transaksi 2 jam
 *   php artisan payment:auto all       ketiganya
 *
 * Digabung dalam satu perintah dengan alasan yang sama seperti
 * `telegram:auto` (8.9): semuanya berbagi pembacaan config, awalan log, dan
 * penanganan galat yang mengirim peringatan alih-alih mati diam-diam.
 */
class PaymentAutomation extends Command
{
    protected $signature = 'payment:auto
                            {tugas=all : verify, expire, stale, atau all}';

    protected $description = 'Verifikasi pembayaran tertunda, kedaluwarsakan langganan, dan buang tagihan basi';

    public function __construct(
        protected InvoiceService $invoices,
        protected MembershipService $membership,
        protected TelegramAlertService $alerts,
        protected TelegramServiceInterface $telegram
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tugas = (string) $this->argument('tugas');

        $daftar = $tugas === 'all' ? ['verify', 'expire', 'stale'] : [$tugas];

        $gagal = 0;

        foreach ($daftar as $satu) {

            try {
                match ($satu) {
                    'verify' => $this->verify(),
                    'expire' => $this->expire(),
                    'stale'  => $this->stale(),
                    default  => $this->components->error("Tugas `{$satu}` tidak dikenal."),
                };

            } catch (Throwable $e) {

                $gagal++;

                $this->components->error("{$satu} gagal: ".$e->getMessage());

                // Perintah terjadwal yang mati diam-diam adalah perintah yang
                // dikira berjalan padahal tidak.
                $this->alerts->schedulerError('payment:auto '.$satu, $e->getMessage());
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /*
    |--------------------------------------------------------------------------
    | Verifikasi
    |--------------------------------------------------------------------------
    */

    /**
     * Antrekan verifikasi untuk transaksi yang masih menunggu.
     *
     * Yang sudah melewati `verify.max_attempts` dilewati. Transaksi yang tidak
     * akan pernah dibayar akan ditanyakan selamanya tanpa batas itu, dan yang
     * menutupnya nanti adalah kedaluwarsa tagihannya.
     */
    private function verify(): void
    {
        $maks = (int) config('payment.verify.max_attempts', 12);

        $ids = PaymentTransaction::query()
            ->where('status', \App\Enums\PaymentStatus::PENDING->value)
            ->where('verify_attempts', '<', $maks)
            ->whereNotNull('payment_provider_id')
            // Provider manual tidak punya apa pun untuk ditanyakan; yang
            // mengubah statusnya adalah admin.
            ->whereHas('provider', fn ($q) => $q->where('driver', '!=', 'manual'))
            ->orderBy('updated_at')
            ->limit((int) config('payment.verify.batch', 50))
            ->pluck('id');

        foreach ($ids as $id) {
            VerifyPaymentTransaction::dispatch((int) $id);
        }

        $this->log('payment.auto.verify', ['jumlah' => $ids->count()]);

        $this->components->info($ids->isEmpty()
            ? 'Tidak ada transaksi yang perlu diverifikasi.'
            : $ids->count().' transaksi diantrekan untuk verifikasi.');
    }

    /*
    |--------------------------------------------------------------------------
    | Kedaluwarsa
    |--------------------------------------------------------------------------
    */

    /**
     * Tutup tagihan yang lewat jatuh tempo, dan akhiri langganan yang habis.
     *
     * Keduanya di satu tugas karena keduanya adalah "waktu sudah lewat, ubah
     * statusnya" dan keduanya harus berjalan sesering yang sama. Memisahkannya
     * berarti dua jadwal yang bisa tidak sinkron.
     */
    private function expire(): void
    {
        $tagihan = $this->invoices->expireOverdue();

        $langganan = $this->membership->expireDue();

        $this->log('payment.auto.expire', [
            'invoice'      => $tagihan,
            'subscription' => $langganan,
        ]);

        $this->components->info(sprintf(
            '%d tagihan dikedaluwarsakan, %d langganan diakhiri.',
            $tagihan,
            $langganan
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Tagihan basi
    |--------------------------------------------------------------------------
    */

    /**
     * Buang tagihan yang tidak pernah menerima transaksi.
     *
     * Tiga hal per tagihan basi, dalam urutan yang tidak saling bergantung:
     *
     * 1. `InvoiceService::expireStale()` sudah mengubah statusnya jadi
     *    CANCELLED — itu yang membuatnya lenyap dari tab "Menunggu" di panel
     *    admin dan dari `pendingInvoice()` PremiumHandler.
     * 2. Langganan PENDING yang menunjuk ke tagihan itu ikut dibatalkan, atau
     *    riwayat pengguna akan menunjukkan "menunggu pembayaran" selamanya
     *    untuk tagihan yang sudah tidak bisa dibayar.
     * 3. Pesan bot yang berisi tombol "Bayar sekarang" dihapus dari obrolan
     *    Telegram, kalau id-nya tersimpan. Tanpa ini, pengguna masih melihat
     *    tombol yang mengarah ke tagihan yang sudah tidak berlaku.
     *
     * Kegagalan menghapus satu pesan (chat sudah diblokir pengguna, pesan
     * sudah dihapus manual, dll) tidak boleh menggagalkan pembatalan tagihan
     * lain dalam batch yang sama — karena itu ditangkap per baris, bukan
     * membiarkan satu galat menghentikan seluruh perintah.
     */
    private function stale(): void
    {
        $invoices = $this->invoices->expireStale();

        foreach ($invoices as $invoice) {

            $this->membership->cancelPendingFor($invoice);

            if ($invoice->telegram_chat_id === null || $invoice->telegram_message_id === null) {
                continue;
            }

            try {
                $this->telegram->deleteMessage(
                    (int) $invoice->telegram_chat_id,
                    (int) $invoice->telegram_message_id
                );

            } catch (Throwable $e) {

                Log::warning('payment.auto.stale_delete_failed', [
                    'invoice' => $invoice->number,
                    'sebab'   => $e->getMessage(),
                ]);
            }
        }

        $this->log('payment.auto.stale', ['jumlah' => $invoices->count()]);

        $this->components->info($invoices->isEmpty()
            ? 'Tidak ada tagihan basi.'
            : $invoices->count().' tagihan basi dibatalkan dan dibuang dari panel & bot.');
    }

    private function log(string $event, array $context): void
    {
        if (! config('payment.logging.enabled', true)) {
            return;
        }

        Log::channel(config('payment.logging.channel') ?: config('logging.default'))
            ->info($event, $context);
    }
}