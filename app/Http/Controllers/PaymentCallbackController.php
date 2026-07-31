<?php

namespace App\Http\Controllers;

use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\Exceptions\WebhookTestException;
use App\Services\Payments\PaymentAlertService;
use App\Services\Payments\PaymentCallbackService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint callback provider pembayaran.
 *
 * Satu route untuk semua provider: `/payment/callback/{provider}`. Slug-nya
 * menentukan provider mana, dan driver-nya yang tahu cara membaca payload
 * serta memverifikasi tanda tangannya. Menambah Stripe tidak menambah route.
 *
 * ## Kenapa kegagalan menjawab kode HTTP yang berbeda-beda
 *
 * Provider memutuskan mengirim ulang atau tidak berdasarkan kode yang kita
 * kembalikan. Itu bukan detail kosmetik:
 *
 * - **200** untuk yang berhasil DAN untuk callback ganda. Ganti adalah
 *   perilaku normal, bukan kesalahan; menjawab galat membuat provider
 *   mengirimnya lagi, selamanya.
 * - **400** untuk tanda tangan tidak sah dan referensi tidak dikenal.
 *   Mengirim ulang tidak akan mengubah apa pun, dan 200 akan membuat
 *   percobaan penyalahgunaan tampak berhasil di dashboard provider.
 * - **500** hanya untuk kegagalan kita sendiri — basis data mati, bug. Di
 *   situlah pengiriman ulang justru berguna, karena masalahnya sementara.
 *
 * Route ini dikecualikan dari CSRF (provider tidak punya token kita) dan
 * dibatasi lajunya, karena ia terbuka ke internet tanpa autentikasi apa pun.
 */
class PaymentCallbackController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PaymentCallbackService $callbacks,
        protected PaymentAlertService $alerts
    ) {
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        /*
        |----------------------------------------------------------------------
        | Catat SEBELUM apa pun diperiksa
        |----------------------------------------------------------------------
        |
        | Ini baris pertama yang berjalan, sebelum provider dicari dan sebelum
        | tanda tangan diverifikasi. Alasannya: "webhook tidak pernah sampai"
        | dan "webhook sampai lalu ditolak" adalah dua masalah yang sama sekali
        | berbeda, dengan gejala yang identik dari luar — dan tanpa baris ini
        | keduanya tidak bisa dibedakan sama sekali.
        |
        | Yang dicatat: alamat pengirim, nama header (BUKAN nilainya), dan isi
        | body. Nilai header sengaja tidak ikut karena di situlah tokennya
        | berada; nama-namanya saja sudah cukup untuk membedakan "header salah
        | nama" dari "token salah isi".
        |
        */
        $this->logRaw($request, $provider);

        try {
            $model = $this->gateways->find($provider);

        } catch (PaymentException) {

            // Provider tidak dikenal. 404, bukan 400: yang salah alamatnya,
            // bukan isinya.
            return response()->json(['ok' => false, 'message' => 'Provider tidak dikenal.'], 404);
        }

        try {
            $transaction = $this->callbacks->handle(
                $model,
                $request->all(),
                $this->headers($request)
            );

            return response()->json([
                'ok'        => true,
                'reference' => $transaction->reference,
                'status'    => $transaction->status->value,
            ]);

        } catch (WebhookTestException $e) {

            // Uji coba dari dashboard provider. Tokennya cocok, jadi dari
            // sudut pandang dashboard ini memang berhasil — 400 di sini
            // membuat tombol Test selalu tampak gagal meski pemasangannya
            // sudah benar. Ditangkap SEBELUM PaymentException karena ia
            // turunannya.
            return response()->json(['ok' => true, 'message' => $e->getMessage()]);

        } catch (PaymentException $e) {

            $this->notify($e, $model->slug, $request);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 400);

        } catch (Throwable $e) {

            // Kegagalan kita sendiri. 500 supaya provider mengirim ulang —
            // di sinilah pengiriman ulang memang yang diinginkan.
            report($e);

            return response()->json(['ok' => false, 'message' => 'Gagal memproses.'], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Catat permintaan masuk apa adanya.
     *
     * Body ikut seluruhnya. Payload Trakteer memuat nama pendukung dan
     * pesannya — itu memang data pribadi, tetapi tanpa payload lengkapnya
     * pembayaran yang nomor tagihannya salah ketik tidak bisa dicocokkan
     * manual, dan uang orang menggantung tanpa jejak. Log-nya berputar
     * sendiri lewat channel `daily`.
     */
    private function logRaw(Request $request, string $provider): void
    {
        Log::channel(config('payment.logging.channel') ?: config('logging.default'))
            ->info('payment.callback.raw', [
                'provider' => $provider,
                'ip'       => $request->ip(),
                'ua'       => substr((string) $request->userAgent(), 0, 120),
                // Nama header saja. Nilainya memuat token.
                'headers'  => array_keys($this->headers($request)),
                'body'     => $request->all(),
            ]);
    }

    /**
     * Header dalam huruf kecil semua.
     *
     * Nama header tidak peka besar-kecil huruf menurut HTTP, dan proxy
     * mengubahnya sesuka hati. Driver membandingkan dengan nama huruf kecil,
     * jadi normalisasinya dilakukan sekali di sini alih-alih diingat setiap
     * driver.
     *
     * @return array<string,string>
     */
    private function headers(Request $request): array
    {
        $hasil = [];

        foreach ($request->headers->all() as $nama => $nilai) {
            $hasil[strtolower($nama)] = is_array($nilai) ? (string) ($nilai[0] ?? '') : (string) $nilai;
        }

        return $hasil;
    }

    /**
     * Beri tahu operator untuk kegagalan yang patut diketahui.
     *
     * Tidak semuanya: perpindahan status yang ditolak dan callback ganda
     * adalah keadaan normal yang sudah tercatat di log, dan
     * memberitahukannya hanya membuat peringatan diabaikan.
     */
    private function notify(PaymentException $e, string $provider, Request $request): void
    {
        $pesan = $e->getMessage();

        if (str_contains($pesan, 'Tanda tangan')) {
            $this->alerts->invalidSignature($provider, $request->ip());

            return;
        }

        if (str_contains($pesan, 'tidak dikenal')) {
            $this->alerts->unknownReference($provider, $pesan);
        }
    }
}
