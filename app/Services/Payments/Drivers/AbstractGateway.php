<?php

namespace App\Services\Payments\Drivers;

use App\Support\Concerns\LogsPaymentEvents;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
    use LogsPaymentEvents;

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

    /**
     * Byte body apa adanya, atau tolak.
     *
     * ## Kenapa null ditolak alih-alih disusun ulang dari `$payload`
     *
     * Menyusun ulang JSON dari array yang sudah di-parse TIDAK menghasilkan
     * byte yang sama dengan yang dikirim provider:
     *
     * - `json_encode` meng-escape `/` jadi `\/` kecuali diminta tidak;
     *   provider belum tentu melakukannya.
     * - Karakter non-ASCII jadi `\uXXXX` atau tetap UTF-8, tergantung flag.
     * - `"amount": 1500.00` kembali sebagai `1500` — PHP tidak menyimpan
     *   jejak nol di belakang koma.
     * - Urutan kunci mengikuti urutan parse, bukan urutan aslinya di kabel.
     *
     * Satu saja dari itu berbeda, HMAC-nya berbeda seluruhnya. Fallback
     * diam-diam ke hasil susun ulang berarti tanda tangan yang sah selalu
     * ditolak, dengan pesan yang menunjuk ke arah yang salah — orang akan
     * mengganti secret berkali-kali padahal secret-nya tidak pernah keliru.
     *
     * Lebih baik berhenti dengan menyebut sebab yang sebenarnya.
     *
     * @throws PaymentException
     */
    protected function rawBody(PaymentProvider $provider, ?string $rawBody): string
    {
        if ($rawBody === null || $rawBody === '') {

            throw PaymentException::providerUnusable(
                $provider->name,
                'body mentah callback tidak tersedia, padahal driver ini menghitung '
                .'tanda tangan atasnya. Ini terjadi bila callback diproses lewat jalur '
                .'non-HTTP — verifikasi terjadwal atau `payment:webhook-test` tanpa '
                .'opsi --raw. Uji lewat curl ke URL callback yang sungguhan.'
            );
        }

        return $rawBody;
    }

    /**
     * Nilai tanda tangan dari header, nama mana pun yang dipakai.
     *
     * Beberapa nama dicoba karena proxy dan panel hosting kadang menulis
     * ulang atau menambah awalan pada header non-standar. Yang dikembalikan
     * nilai pertama yang tidak kosong; bila tidak ada sama sekali, string
     * kosong — dan `signatureMatches()` akan menolaknya.
     *
     * @param  array<string,string>  $headers  huruf kecil semua
     */
    protected function signatureFrom(array $headers, string ...$names): string
    {
        foreach ($names as $nama) {

            $nilai = trim((string) ($headers[strtolower($nama)] ?? ''));

            if ($nilai !== '') {
                return $nilai;
            }
        }

        return '';
    }

    /**
     * Cocokkan tanda tangan, atau tolak dengan jejak yang bisa ditelusuri.
     *
     * Yang dicatat saat gagal adalah **nama** header yang diterima, bukan
     * nilainya — di situlah tandanya berada. Itu cukup untuk membedakan dua
     * kegagalan yang gejalanya identik dari luar tetapi perbaikannya sama
     * sekali berbeda: header salah nama (provider mengirim di tempat lain)
     * versus secret salah isi.
     *
     * ## Kenapa namanya bukan `assertSignature`
     *
     * `TrakteerGateway` sudah punya `private assertSignature()` miliknya
     * sendiri dengan bentuk argumen yang berbeda. Memakai nama itu di sini
     * membuat PHP menolak memuat kelasnya sama sekali — bukan galat saat
     * dipanggil, melainkan fatal error saat autoload, karena anak tidak boleh
     * mempersempit visibilitas induk dari protected jadi private.
     *
     * Menamainya berbeda lebih murah daripada menulis ulang verifikasi
     * Trakteer yang sudah berjalan di produksi.
     *
     * @param  array<string,string>  $headers
     *
     * @throws PaymentException
     */
    protected function guardSignature(
        PaymentProvider $provider,
        string $diharapkan,
        string $diterima,
        array $headers = []
    ): void {

        if ($this->signatureMatches($diharapkan, $diterima)) {
            return;
        }

        $this->log('warning', 'callback.invalid_signature', [
            'provider' => $provider->slug,
            'header'   => array_values(array_filter(
                array_keys($headers),
                fn (string $h) => str_contains($h, 'sign')
                    || str_contains($h, 'token')
                    || str_contains($h, 'hmac')
            )),
            'diterima_kosong' => $diterima === '',
        ]);

        throw PaymentException::invalidSignature($provider->slug);
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
