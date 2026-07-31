<?php

namespace App\Console\Commands;

use App\Jobs\VerifyPaymentTransaction;
use App\Models\PaymentTransaction;
use App\Services\Membership\MembershipService;
use App\Services\Payments\InvoiceService;
use App\Services\Telegram\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Perawatan otomatis pembayaran dan membership.
 *
 *   php artisan payment:auto verify    tanyakan transaksi yang masih menunggu
 *   php artisan payment:auto expire    kedaluwarsakan tagihan & langganan
 *   php artisan payment:auto all       keduanya
 *
 * Digabung dalam satu perintah dengan alasan yang sama seperti
 * `telegram:auto` (8.9): ketiganya berbagi pembacaan config, awalan log, dan
 * penanganan galat yang mengirim peringatan alih-alih mati diam-diam.
 */
class PaymentAutomation extends Command
{
    protected $signature = 'payment:auto
                            {tugas=all : verify, expire, atau all}';

    protected $description = 'Verifikasi pembayaran tertunda dan kedaluwarsakan langganan';

    public function __construct(
        protected InvoiceService $invoices,
        protected MembershipService $membership,
        protected TelegramAlertService $alerts
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tugas = (string) $this->argument('tugas');

        $daftar = $tugas === 'all' ? ['verify', 'expire'] : [$tugas];

        $gagal = 0;

        foreach ($daftar as $satu) {

            try {
                match ($satu) {
                    'verify' => $this->verify(),
                    'expire' => $this->expire(),
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

    private function log(string $event, array $context): void
    {
        if (! config('payment.logging.enabled', true)) {
            return;
        }

        Log::channel(config('payment.logging.channel') ?: config('logging.default'))
            ->info($event, $context);
    }
}
