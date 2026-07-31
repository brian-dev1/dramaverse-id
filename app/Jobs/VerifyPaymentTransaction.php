<?php

namespace App\Jobs;

use App\Models\PaymentTransaction;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentCallbackService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tanyakan sendiri keadaan sebuah pembayaran ke provider.
 *
 * ## Kenapa ini ada meski callback sudah ada
 *
 * Callback bisa tidak pernah sampai. Server sedang restart saat provider
 * mengirim, firewall menolaknya, deploy sedang berjalan — semuanya biasa, dan
 * tidak satu pun meninggalkan jejak di sisi kita. Yang terlihat pengguna:
 * sudah bayar, membership tidak aktif.
 *
 * Bertanya sendiri menutup celah itu. Hasilnya diterapkan lewat
 * `PaymentCallbackService` yang sama dengan callback — jadi idempotensi,
 * pencocokan nominal, dan penjagaan perpindahan status berlaku persis sama.
 * Tidak ada jalur kedua dengan aturan sendiri.
 */
class VerifyPaymentTransaction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Sekali saja per jalan.
     *
     * Pengulangan diurus scheduler yang menjalankannya lagi beberapa menit
     * kemudian, bukan oleh antrean yang mengulang dalam hitungan detik.
     * Transaksi yang belum dibayar tidak akan tiba-tiba dibayar tiga detik
     * kemudian, dan menanyakannya berulang hanya membebani kuota API provider.
     */
    public int $tries = 1;

    public function __construct(
        public int $transactionId
    ) {
        $this->onQueue(config('payment.verify.queue', 'default'));
    }

    public function handle(
        PaymentGatewayManager $gateways,
        PaymentCallbackService $callbacks
    ): void {

        $tx = PaymentTransaction::with('provider')->find($this->transactionId);

        if ($tx === null || ! $tx->needsVerification()) {
            return;
        }

        $provider = $tx->provider;

        if ($provider === null) {
            return;
        }

        // Percobaan dinaikkan LEBIH DULU. Kalau dinaikkan setelah berhasil,
        // transaksi yang selalu melempar tidak akan pernah mencapai batas dan
        // akan ditanyakan selamanya.
        $tx->increment('verify_attempts');

        try {
            $hasil = $gateways->for($provider)->verify($provider, $tx);

            $callbacks->apply($tx, $hasil, 'verify');

        } catch (PaymentException $e) {

            // Sudah dicatat lengkap di service. Disimpan ke barisnya supaya
            // admin melihat sebabnya di panel, bukan hanya di log.
            $tx->forceFill(['last_error' => $e->getMessage()])->save();

        } catch (Throwable $e) {

            Log::channel(config('payment.logging.channel') ?: config('logging.default'))
                ->warning('payment.verify.error', [
                    'transaction_id' => $this->transactionId,
                    'sebab'          => $e->getMessage(),
                ]);
        }
    }
}
