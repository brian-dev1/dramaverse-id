<?php

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentCharge;
use App\Services\Payments\PaymentResult;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Xoftware Pay — agregator pembayaran Indonesia.
 *
 * Menyediakan QRIS, e-wallet (DANA/OVO/ShopeePay/LinkAja), dan Virtual
 * Account lewat satu API. Satu baris `payment_providers` mewakili SATU
 * channel: kredensial `channel_code` yang menentukan. Ingin menerima QRIS dan
 * DANA sekaligus berarti dua baris provider dengan `api_key` yang sama dan
 * `channel_code` yang berbeda — bukan satu baris yang menawarkan pilihan.
 *
 * Itu mengikuti bentuk yang sudah ada: halaman checkout menampilkan daftar
 * provider, bukan daftar channel di dalam provider, dan biaya layanan
 * (`fee_percent`/`fee_flat`) juga sudah per baris provider.
 *
 * ## Dua tanda tangan yang berbeda, jangan tertukar
 *
 * Ini sumber kebingungan yang paling mahal di integrasi ini, jadi ditulis di
 * depan:
 *
 * | | Permintaan KELUAR (charge/verify/cancel) | Callback MASUK (webhook) |
 * |---|---|---|
 * | Kunci | `api_key` | `webhook_secret` |
 * | Yang di-hash | `ts\nMETHOD\nPATH\nbody` | body mentah saja |
 * | Encoding | **Base64** | **Hex** |
 * | Header | `X-Signature` | `X-Signature` |
 *
 * Nama headernya sama persis sementara kunci, isi, dan encoding-nya semua
 * berbeda. Memakai `api_key` untuk memverifikasi webhook menghasilkan
 * penolakan yang terlihat seperti "secret salah", padahal secret-nya benar —
 * cuma yang dipakai bukan yang seharusnya.
 *
 * ## Byte yang ditandatangani harus byte yang dikirim
 *
 * Dokumentasi Xoftware Pay memperingatkannya sendiri: *"Any change to the JSON
 * body after signing will result in a signature mismatch."*
 *
 * Karena itu `request()` di bawah menyusun JSON SEKALI ke dalam `$raw`, lalu
 * menandatangani `$raw` DAN mengirim `$raw` yang sama lewat `withBody()`.
 * Yang TIDAK boleh dilakukan adalah `->post($url, $array)` — Laravel akan
 * meng-encode ulang array itu dengan flag pilihannya sendiri, dan hasilnya
 * bisa berbeda byte dari yang barusan ditandatangani.
 *
 * Ini kembar dari masalah `parseCallback()`: di sana body mentah dibutuhkan
 * karena datang dari luar, di sini karena dikirim ke luar. Arahnya berlawanan,
 * sebabnya identik.
 *
 * ## Yang TIDAK didukung, dan kenapa
 *
 * Channel e-wallet ditolak driver ini. Xoftware Pay mewajibkan `name`,
 * `email`, dan `ewallet_phone` pelanggan untuk DANA/OVO/ShopeePay/LinkAja,
 * sementara DramaVerse tidak pernah mengumpulkan nomor telepon sama sekali
 * (tidak ada kolomnya di tabel `users`) dan email-nya nullable karena
 * mayoritas pengguna masuk lewat Telegram.
 *
 * Mengirim nilai karangan supaya permintaannya lolos validasi adalah cara
 * paling cepat membuat uang tersangkut di akun orang lain. Jadi ditolak di
 * muka, dengan menyebut apa yang kurang. QRIS dan Virtual Account tidak
 * memerlukan itu dan berjalan normal.
 */
class XoftwarePayGateway extends AbstractGateway
{
    /** Channel yang mewajibkan nomor telepon pelanggan. */
    private const CHANNEL_EWALLET = ['DANA', 'OVO', 'SHOPEEPAY', 'LINKAJA'];

    /** Nominal terendah yang diterima Xoftware Pay. */
    private const MINIMAL = 1000;

    private const PATH_CREATE = '/v1/api/transactions';

    private const PATH_STATUS = '/v1/api/transactions/status';

    private const PATH_CANCEL = '/v1/api/transactions/cancel';


    /*
    |--------------------------------------------------------------------------
    | Membuat pembayaran
    |--------------------------------------------------------------------------
    */

    /**
     * @throws PaymentException
     */
    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge {

        $channel = $this->channel($provider);

        $this->assertChannelUsable($provider, $channel, $invoice);

        /*
        |----------------------------------------------------------------------
        | Nominal dibulatkan ke bilangan bulat
        |----------------------------------------------------------------------
        |
        | `amount` bertipe Int64 di sisi Xoftware Pay, sementara kolom kita
        | `decimal(12,2)`. Mengirim `50000.00` ke field integer bisa ditolak
        | atau — lebih buruk — diterima lalu dibulatkan diam-diam ke angka
        | yang berbeda dari yang ditagihkan.
        |
        | Dibulatkan, bukan dipotong: rupiah tidak pernah punya pecahan dalam
        | praktiknya, dan `(int) 49999.999999` hasil aritmetika float akan
        | menjadi 49999 — selisih satu rupiah yang membuat pembayaran ditolak
        | penjagaan nominal di `PaymentCallbackService`.
        |
        */
        $nominal = (int) round((float) $transaction->amount);

        if ($nominal < self::MINIMAL) {

            throw PaymentException::gatewayFailed(
                $provider->name,
                sprintf(
                    'nominal Rp %s di bawah batas minimum Xoftware Pay (Rp %s).',
                    number_format($nominal, 0, ',', '.'),
                    number_format(self::MINIMAL, 0, ',', '.')
                )
            );
        }

        $body = array_filter([
            'merchant_id'        => $this->merchantId($provider),
            'channel_code'       => $channel,
            'amount'             => $nominal,
            'ref_id'             => $transaction->reference,
            'fee_direction'      => $this->feeDirection($provider),
            'notify_url'         => url('/payment/callback/'.$provider->slug),
            'return_url'         => $this->returnUrl($invoice),
            'expires_in_minutes' => $this->expiresInMinutes($invoice),
            'note'               => $this->note($invoice),
            'metadata'           => $this->metadata($invoice),
        ], fn ($nilai) => $nilai !== null && $nilai !== '');

        $data = $this->request($provider, 'POST', self::PATH_CREATE, $body);

        /*
        |----------------------------------------------------------------------
        | Tiga bentuk instruksi bayar, satu field tujuan
        |----------------------------------------------------------------------
        |
        | Xoftware Pay mengembalikan `qris_text` untuk QRIS, `code` untuk VA
        | dan retail, atau `url` untuk aplikasi eksternal — tergantung channel,
        | dan hanya salah satunya yang terisi.
        |
        | Hanya `url` yang cocok dimasukkan ke `checkoutUrl`, karena field itu
        | dipakai halaman checkout untuk MENGALIHKAN pengguna. Menaruh string
        | QRIS di situ akan membuat browser mencoba membukanya sebagai alamat.
        |
        | Dua yang lain tetap tersimpan lengkap di `raw`, yang jatuh ke
        | `payment_transactions.response_payload`. Dari situ halaman
        | pembayaran bisa menampilkannya, dan kalau terjadi sengketa isinya
        | masih ada apa adanya.
        |
        */
        $url = $this->teks($data, 'url');

        return new PaymentCharge(
            externalId: $this->teks($data, 'transaction_id'),
            checkoutUrl: $url !== '' ? $url : null,
            status: $this->statusTransaksi($this->teks($data, 'status') ?: 'PENDING'),
            raw: $data,
            expiresAt: $this->waktu($data, 'expires_at') ?? $invoice->due_at,
            method: strtolower($channel),
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Menanyakan keadaan
    |--------------------------------------------------------------------------
    */

    /**
     * @throws PaymentException
     */
    public function verify(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        $data = $this->request($provider, 'POST', self::PATH_STATUS, [
            'ref_id' => $transaction->reference,
        ]);

        return $this->hasilDari($data, $transaction->reference);
    }


    /*
    |--------------------------------------------------------------------------
    | Membaca callback
    |--------------------------------------------------------------------------
    */

    /**
     * @throws PaymentException
     */
    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = [],
        ?string $rawBody = null
    ): PaymentResult {

        /*
        |----------------------------------------------------------------------
        | Tanda tangan diperiksa PALING DULU
        |----------------------------------------------------------------------
        |
        | Sebelum satu field pun dibaca dari payload. Callback yang tidak sah
        | adalah seseorang yang mencoba mengaktifkan membership tanpa
        | membayar, dan tidak boleh ada satu pun keputusan yang diambil
        | berdasarkan isinya sebelum keasliannya terbukti.
        |
        */
        $secret = $this->credential($provider, 'webhook_secret');

        $body = $this->rawBody($provider, $rawBody);

        /*
        | Hex, huruf kecil disamakan.
        |
        | `hash_hmac` mengembalikan hex huruf kecil, sementara pengirim boleh
        | saja memakai huruf besar — dan `hash_equals` peka besar-kecil huruf.
        | Menyamakan huruf tidak melemahkan apa pun: hex tidak menyimpan
        | informasi apa-apa pada besar-kecil hurufnya, jadi ruang tebakan
        | penyerang sama sekali tidak berubah. Yang berubah hanya: pemasangan
        | yang benar tidak lagi gagal karena beda kapitalisasi.
        */
        $this->guardSignature(
            $provider,
            hash_hmac('sha256', $body, $secret),
            strtolower($this->signatureFrom($headers, 'x-signature')),
            $headers
        );

        /*
        |----------------------------------------------------------------------
        | `order_id`, bukan `ref_id`
        |----------------------------------------------------------------------
        |
        | Payload webhook menamai referensi kita `order_id`, sementara API
        | transaksi menamainya `ref_id`. Nilainya sama — yang kita kirim saat
        | charge — hanya namanya yang berbeda di dua tempat. `ref_id` tetap
        | dibaca sebagai cadangan seandainya penamaannya diseragamkan nanti.
        |
        */
        $referensi = $this->teks($payload, 'order_id') ?: $this->teks($payload, 'ref_id');

        if ($referensi === '') {

            $this->log('warning', 'callback.unmatched', [
                'provider' => $provider->slug,
                'event'    => $this->teks($payload, 'event_id'),
                'payload'  => $payload,
            ]);

            throw PaymentException::unknownReference(
                'callback Xoftware Pay tidak memuat `order_id`'
            );
        }

        $status = $this->teks($payload, 'status');

        $this->log('info', 'callback.xoftwarepay', [
            'provider'  => $provider->slug,
            'event'     => $this->teks($payload, 'event_id'),
            'reference' => $referensi,
            'status'    => $status,
        ]);

        return new PaymentResult(
            status: $this->statusTransaksi($status),
            reference: $referensi,
            externalId: $this->teks($payload, 'transaction_id') ?: null,
            amount: $this->nominal($payload),
            method: strtolower($this->teks($payload, 'channel_code')) ?: null,
            raw: $payload,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Pembatalan
    |--------------------------------------------------------------------------
    */

    /**
     * Batalkan transaksi yang masih menunggu.
     *
     * Xoftware Pay menandai transaksi yang dibatalkan sebagai `FAILED`, tetapi
     * yang dikembalikan ke sini `CANCELLED`. Keduanya bukan hal yang sama dari
     * sudut pandang panel: `FAILED` berarti pembayaran dicoba lalu gagal,
     * `CANCELLED` berarti seseorang membatalkannya dengan sengaja. Membedakan
     * keduanya penting saat menelusuri kenapa sebuah tagihan tidak pernah
     * lunas.
     *
     * @throws PaymentException
     */
    public function cancel(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        $data = $this->request($provider, 'POST', self::PATH_CANCEL, [
            'ref_id' => $transaction->reference,
        ]);

        return new PaymentResult(
            status: PaymentStatus::CANCELLED,
            reference: $transaction->reference,
            externalId: $this->teks($data, 'transaction_id') ?: $transaction->external_id,
            raw: $data,
            message: 'Dibatalkan lewat API Xoftware Pay.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Lapisan HTTP
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim satu permintaan bertanda tangan, kembalikan isinya.
     *
     * @param  array<string,mixed>|null  $body  null untuk GET tanpa body
     * @return array<string,mixed>
     *
     * @throws PaymentException
     */
    private function request(
        PaymentProvider $provider,
        string $method,
        string $path,
        ?array $body = null
    ): array {

        $apiKey = $this->credential($provider, 'api_key');

        /*
        | JSON disusun SEKALI. String inilah yang ditandatangani dan string
        | ini juga yang dikirim — lihat catatan di docblock kelas.
        |
        | Flag-nya sendiri tidak penting justru KARENA keduanya memakai string
        | yang sama; yang penting hanya bahwa tidak ada encoding kedua di
        | antara menandatangani dan mengirim.
        */
        $raw = $body === null
            ? ''
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();

        $signature = base64_encode(
            hash_hmac('sha256', $timestamp."\n".$method."\n".$path."\n".$raw, $apiKey, true)
        );

        $permintaan = $this->http()->withHeaders([
            'X-API-Key'   => $apiKey,
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature,
        ]);

        $url = rtrim($this->credential($provider, 'base_url'), '/').$path;

        try {
            $response = $raw === ''
                ? $permintaan->get($url)
                : $permintaan->withBody($raw, 'application/json')->send($method, $url);

        } catch (Throwable $e) {

            /*
            | Kegagalan jaringan dibedakan dari penolakan gateway.
            |
            | Yang ini berarti permintaannya mungkin tidak pernah sampai — dan
            | itu keadaan yang sama sekali berbeda dari "sampai lalu ditolak".
            | Jaring pengaman `VerifyPaymentTransaction` yang menanganinya
            | nanti, jadi yang penting di sini hanya jangan sampai tertukar.
            */
            $this->log('error', 'gateway.unreachable', [
                'provider' => $provider->slug,
                'path'     => $path,
                'sebab'    => $e->getMessage(),
            ]);

            throw PaymentException::gatewayFailed(
                $provider->name,
                'tidak bisa dihubungi: '.$e->getMessage()
            );
        }

        return $this->bacaResponse($provider, $response, $path);
    }

    /**
     * Baca badan jawaban, atau tolak dengan sebab yang bisa dibaca orang.
     *
     * @return array<string,mixed>
     *
     * @throws PaymentException
     */
    private function bacaResponse(PaymentProvider $provider, Response $response, string $path): array
    {
        $json = $response->json();

        if (! is_array($json)) {

            $this->log('error', 'gateway.malformed', [
                'provider' => $provider->slug,
                'path'     => $path,
                'http'     => $response->status(),
                'body'     => substr($response->body(), 0, 500),
            ]);

            throw PaymentException::gatewayFailed(
                $provider->name,
                'jawaban bukan JSON yang bisa dibaca (HTTP '.$response->status().').'
            );
        }

        /*
        |----------------------------------------------------------------------
        | Amplop `{code, error, message, data}` diperlakukan sebagai OPSIONAL
        |----------------------------------------------------------------------
        |
        | Dokumentasi Xoftware Pay menyatakan seluruh jawaban memakai amplop
        | itu, TETAPI contoh-contoh di halaman Transaction API menampilkan
        | objek transaksi langsung di akar tanpa amplop. Keduanya tidak
        | mungkin benar sekaligus.
        |
        | Mana yang sebenarnya berlaku hanya bisa dipastikan dengan akun
        | sungguhan. Karena itu keduanya diterima: kalau ada `data`, isinya
        | yang dipakai; kalau tidak, akarnya sendiri. Menebak salah satu lalu
        | menuliskannya sebagai kepastian berarti separuh kemungkinan gagal
        | pada pembayaran pertama orang sungguhan.
        |
        */
        if (($json['error'] ?? false) === true || $response->failed()) {

            $pesan = $this->teks($json, 'message')
                ?: 'ditolak dengan HTTP '.$response->status();

            $this->log('warning', 'gateway.rejected', [
                'provider' => $provider->slug,
                'path'     => $path,
                'http'     => $response->status(),
                'pesan'    => $pesan,
            ]);

            throw PaymentException::gatewayFailed($provider->name, $pesan);
        }

        $data = $json['data'] ?? $json;

        return is_array($data) ? $data : $json;
    }


    /*
    |--------------------------------------------------------------------------
    | Penyusun payload
    |--------------------------------------------------------------------------
    */

    /**
     * Isi `metadata`, yang wajib ada dan wajib tidak kosong.
     *
     * Xoftware Pay menolak permintaan tanpa `customer` maupun `products`.
     * `products` SELALU bisa kita isi — setiap tagihan pasti punya paket
     * membership — jadi ia yang jadi jaminan permintaan tidak pernah ditolak
     * karena metadata kosong.
     *
     * `customer` ditambahkan hanya sejauh datanya benar-benar ada. Pengguna
     * Telegram tanpa email tidak dikarang emailnya; field yang tidak diketahui
     * dihilangkan, bukan diisi tanda hubung atau alamat palsu yang akan
     * mendarat di struk pembayaran orang.
     *
     * @return array<string,mixed>
     */
    private function metadata(Invoice $invoice): array
    {
        $meta = [
            'products' => [[
                'product_code' => 'PLAN-'.($invoice->membership_plan_id ?? '0'),
                'product_name' => (string) $invoice->plan_name,
            ]],
        ];

        $user = $invoice->user;

        if ($user === null) {
            return $meta;
        }

        $customer = array_filter([
            'id'    => (string) $user->id,
            'name'  => $this->teksNilai($user->name),
            'email' => $this->teksNilai($user->email),
        ], fn ($nilai) => $nilai !== null && $nilai !== '');

        if ($customer !== []) {
            $meta['customer'] = $customer;
        }

        return $meta;
    }

    /**
     * Batas waktu dalam menit, dihitung dari jatuh tempo tagihan.
     *
     * Dijaga tetap di rentang yang masuk akal: nilai negatif (tagihan yang
     * sudah lewat) akan membuat transaksi kedaluwarsa sebelum sempat
     * ditampilkan, dan nilai yang sangat besar membuat tagihan menggantung
     * jauh setelah kita sendiri menganggapnya hangus.
     */
    private function expiresInMinutes(Invoice $invoice): int
    {
        $bawaan = (int) config('payment.invoice_ttl', 1440);

        $menit = $invoice->due_at !== null
            ? (int) ceil(now()->diffInMinutes($invoice->due_at, false))
            : $bawaan;

        return max(5, min($menit > 0 ? $menit : $bawaan, 1440));
    }

    private function returnUrl(Invoice $invoice): ?string
    {
        try {
            return route('web.invoice.show', $invoice->number);

        } catch (Throwable) {

            // Route opsional bagi gateway. Kegagalan menyusunnya tidak boleh
            // menggagalkan pembayaran.
            return null;
        }
    }

    private function note(Invoice $invoice): string
    {
        return mb_substr(
            trim($invoice->plan_name.' — '.$invoice->number),
            0,
            190
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Kredensial
    |--------------------------------------------------------------------------
    */

    /** @throws PaymentException */
    private function channel(PaymentProvider $provider): string
    {
        return strtoupper(trim($this->credential($provider, 'channel_code')));
    }

    /** @throws PaymentException */
    private function merchantId(PaymentProvider $provider): int
    {
        $nilai = trim($this->credential($provider, 'merchant_id'));

        if (! ctype_digit($nilai)) {

            throw PaymentException::providerUnusable(
                $provider->name,
                "Merchant ID `{$nilai}` bukan angka. Salin apa adanya dari dashboard Xoftware Pay."
            );
        }

        return (int) $nilai;
    }

    /**
     * Siapa yang menanggung biaya layanan.
     *
     * Bawaannya `merchant` — dipotong dari settlement. Alternatifnya `user`,
     * yang menambahkannya ke tagihan pengguna. Hanya dua nilai itu yang
     * diterima Xoftware Pay, jadi nilai lain dikembalikan ke bawaan alih-alih
     * diteruskan lalu ditolak di sana.
     */
    private function feeDirection(PaymentProvider $provider): string
    {
        $nilai = strtolower(trim((string) $provider->credential('fee_direction')));

        return in_array($nilai, ['merchant', 'user'], true) ? $nilai : 'merchant';
    }

    /**
     * Tolak channel yang datanya tidak kita miliki.
     *
     * @throws PaymentException
     */
    private function assertChannelUsable(
        PaymentProvider $provider,
        string $channel,
        Invoice $invoice
    ): void {

        if ($channel === '') {

            throw PaymentException::providerUnusable(
                $provider->name,
                'kredensial `channel_code` belum diisi. Isi dengan salah satu kode '
                .'channel Xoftware Pay, misalnya QRIS.'
            );
        }

        if (! in_array($channel, self::CHANNEL_EWALLET, true)) {
            return;
        }

        $user = $invoice->user;

        $kurang = [];

        if (blank($user?->name)) {
            $kurang[] = 'nama';
        }

        if (blank($user?->email)) {
            $kurang[] = 'email';
        }

        // Nomor telepon TIDAK pernah dikumpulkan DramaVerse — tidak ada
        // kolomnya di tabel `users`. Jadi channel e-wallet tidak akan pernah
        // bisa dipenuhi tanpa lebih dulu menambah kolom itu dan memintanya di
        // checkout.
        $kurang[] = 'nomor telepon (belum pernah dikumpulkan DramaVerse)';

        throw PaymentException::providerUnusable(
            $provider->name,
            "channel {$channel} mewajibkan data pelanggan yang tidak kita miliki: "
            .implode(', ', $kurang).'. Pakai QRIS atau Virtual Account.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Pembacaan nilai
    |--------------------------------------------------------------------------
    */

    /**
     * Susun `PaymentResult` dari jawaban status.
     *
     * @param  array<string,mixed>  $data
     */
    private function hasilDari(array $data, string $referensi): PaymentResult
    {
        $status = $this->teks($data, 'status');

        return new PaymentResult(
            status: $this->statusTransaksi($status, $this->teks($data, 'payment_status')),
            reference: $this->teks($data, 'ref_id') ?: $referensi,
            externalId: $this->teks($data, 'transaction_id') ?: null,
            amount: $this->nominal($data),
            method: strtolower($this->teks($data, 'channel_code')) ?: null,
            raw: $data,
        );
    }

    /**
     * Terjemahkan status Xoftware Pay ke kosakata kita.
     *
     * `FAILED` di sisi mereka menutupi dua hal yang berbeda bagi kita: gagal
     * dan kedaluwarsa. `payment_status` yang membedakannya — dan hanya
     * tersedia pada jawaban endpoint status, tidak pada webhook. Itu tidak
     * masalah: keduanya sama-sama keadaan akhir yang tidak mengaktifkan
     * membership, jadi salah menebak di antara keduanya tidak pernah
     * berakibat pada uang siapa pun.
     *
     * Status yang tidak dikenal dipetakan ke PENDING dengan sengaja. Menebak
     * PAID untuk sesuatu yang tidak dipahami berarti memberi membership gratis
     * pada kata yang salah eja; menebak PENDING hanya berarti menunggu, dan
     * `VerifyPaymentTransaction` akan menanyakannya lagi.
     */
    private function statusTransaksi(string $status, string $paymentStatus = ''): PaymentStatus
    {
        return match (strtoupper(trim($status))) {

            'SUCCESS', 'SUCCEEDED', 'PAID' => PaymentStatus::PAID,

            'FAILED', 'CANCELLED', 'EXPIRED' => strtoupper(trim($paymentStatus)) === 'EXPIRED'
                ? PaymentStatus::EXPIRED
                : PaymentStatus::FAILED,

            default => PaymentStatus::PENDING,
        };
    }

    /**
     * Nominal yang benar-benar dibayar.
     *
     * Null bila tidak ada — BUKAN nol. Nol berarti "dibayar nol rupiah" bagi
     * penjagaan nominal di `PaymentCallbackService`, dan itu akan menolak
     * pembayaran yang sebenarnya sah hanya karena fieldnya tidak terbaca.
     * Null berarti "tidak diketahui", yang ditangani berbeda.
     *
     * @param  array<string,mixed>  $data
     */
    private function nominal(array $data): ?float
    {
        foreach (['amount', 'gross'] as $field) {

            $nilai = $data[$field] ?? null;

            if (is_numeric($nilai)) {
                return (float) $nilai;
            }
        }

        return null;
    }

    /**
     * Satu field sebagai string, apa pun bentuk aslinya.
     *
     * @param  array<string,mixed>  $data
     */
    private function teks(array $data, string $field): string
    {
        $nilai = $data[$field] ?? null;

        return is_scalar($nilai) ? trim((string) $nilai) : '';
    }

    private function teksNilai(mixed $nilai): ?string
    {
        return is_scalar($nilai) && trim((string) $nilai) !== ''
            ? trim((string) $nilai)
            : null;
    }

    /**
     * Waktu ISO 8601 dari jawaban, atau null bila tidak terbaca.
     *
     * @param  array<string,mixed>  $data
     */
    private function waktu(array $data, string $field): ?Carbon
    {
        $nilai = $this->teks($data, $field);

        if ($nilai === '') {
            return null;
        }

        try {
            return Carbon::parse($nilai);

        } catch (Throwable) {

            // Batas waktu yang tidak terbaca bukan alasan menggagalkan
            // pembayaran; pemanggilnya sudah menyiapkan cadangan.
            return null;
        }
    }
}
