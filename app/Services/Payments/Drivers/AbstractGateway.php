<?php

namespace App\Services\Payments\Drivers;

use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bagian yang sama di setiap gateway.
 *
 * Yang ditaruh di sini hanya yang benar-benar identik: pembacaan kredensial,
 * penyusunan klien HTTP dengan batas waktu, pencatatan, dan penolakan baku
 * untuk kemampuan yang tidak dimiliki driver.
 *
 * Yang TIDAK ditaruh di sini: apa pun yang berbeda antar provider. Kelas induk
 * yang mencoba menyeragamkan bentuk permintaan akan berakhir sebagai rangkaian
 * `if ($this instanceof ...)` — dan itu justru kebalikan dari pemisahan yang
 * dimaksud.
 */
abstract class AbstractGateway implements PaymentGatewayInterface
{
    /** Batas waktu permintaan ke gateway, dalam detik. */
    protected const TIMEOUT = 20;

    /**
     * Ambil kredensial wajib, atau tolak dengan menyebut yang kurang.
     *
     * @throws PaymentException
     */
    protected function credential(PaymentProvider $provider, string $key): string
    {
        $nilai = $provider->credential($key);

        if ($nilai === null) {
            throw PaymentException::providerUnusable(
                $provider->name,
                "kredensial `{$key}` belum diisi."
            );
        }

        return $nilai;
    }

    /**
     * Klien HTTP dasar.
     *
     * Batas waktunya pendek dengan sengaja. Pengguna sedang menunggu di
     * halaman checkout; gateway yang menggantung tiga puluh detik lebih buruk
     * daripada gateway yang menolak cepat, karena yang kedua masih menyisakan
     * kesempatan mencoba metode lain.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout(static::TIMEOUT)
            ->connectTimeout(5)
            ->acceptJson();
    }

    /**
     * Tolak dengan jelas untuk driver yang belum selesai.
     *
     * @throws PaymentException
     */
    protected function notImplemented(PaymentProvider $provider): never
    {
        throw PaymentException::driverNotImplemented($provider->driver->value);
    }

    /**
     * Bandingkan dua tanda tangan tanpa membocorkan waktu.
     *
     * `hash_equals` membandingkan dalam waktu tetap. Perbandingan `===` biasa
     * berhenti di karakter pertama yang berbeda, dan selisih waktunya — sekecil
     * apa pun — bisa dipakai menebak tanda tangan yang benar karakter demi
     * karakter.
     */
    protected function signatureMatches(string $diharapkan, string $diterima): bool
    {
        return $diterima !== '' && hash_equals($diharapkan, $diterima);
    }

    protected function log(string $level, string $event, array $context = []): void
    {
        if (! config('payment.logging.enabled', true)) {
            return;
        }

        Log::channel(config('payment.logging.channel') ?: config('logging.default'))
            ->log($level, 'payment.'.$event, $context);
    }

    /*
    |--------------------------------------------------------------------------
    | Bawaan yang bisa ditimpa
    |--------------------------------------------------------------------------
    */

    public function cancel(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        // Bawaannya: pembatalan hanya dicatat di sisi kita. Provider yang
        // punya endpoint pembatalan menimpanya.
        return new PaymentResult(
            status: \App\Enums\PaymentStatus::CANCELLED,
            reference: $transaction->reference,
            externalId: $transaction->external_id,
            message: 'Dibatalkan di sisi aplikasi.'
        );
    }

    public function refund(
        PaymentProvider $provider,
        PaymentTransaction $transaction,
        ?float $amount = null
    ): PaymentResult {

        throw PaymentException::providerUnusable(
            $provider->name,
            'driver ini tidak mendukung pengembalian dana lewat API. '
            .'Kembalikan dana secara manual, lalu catat statusnya dari panel.'
        );
    }
}
