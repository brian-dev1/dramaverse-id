<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Contracts\TelegramClientInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Satu-satunya tempat proyek ini berbicara HTTP dengan Telegram.
 *
 * Lihat TelegramClientInterface untuk alasan pemisahannya.
 */
class TelegramClient implements TelegramClientInterface
{
    /** Batas waktu khusus dari withTimeout(). Null berarti pakai config. */
    protected ?int $timeoutOverride = null;

    /** Jumlah percobaan khusus dari withRetries(). Null berarti pakai config. */
    protected ?int $retriesOverride = null;

    /*
    |--------------------------------------------------------------------------
    | Pintu masuk
    |--------------------------------------------------------------------------
    */

    public function call(string $method, array $parameters = [], array $files = []): TelegramResponse
    {
        return $this->send('POST', $method, $parameters, $files);
    }

    public function get(string $method, array $query = []): TelegramResponse
    {
        return $this->send('GET', $method, $query);
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;

        $clone->timeoutOverride = max(1, $seconds);

        return $clone;
    }

    public function withRetries(int $times): static
    {
        $clone = clone $this;

        $clone->retriesOverride = max(1, $times);

        return $clone;
    }

    public function downloadUrl(string $filePath): string
    {
        return $this->baseUrl().'/file/bot'.$this->token().'/'.ltrim($filePath, '/');
    }

    /**
     * Letak berkas di disk mesin ini, bila memang ada di sini.
     *
     * Lihat `config/telegram.php` kunci `api_dir` untuk alasannya. Ringkasnya:
     * Local Bot API Server menyimpan berkas di mesin yang sama, dan membacanya
     * langsung tidak bisa gagal karena endpoint HTTP-nya menjawab 404.
     *
     * Null berarti "tidak ada di sini, pakai HTTP" — bukan kesalahan. Itu
     * jawaban yang benar untuk api.telegram.org.
     */
    public function localPath(string $filePath): ?string
    {
        // Mode `--local` menjawab dengan path absolut; tidak perlu digabung
        // dengan apa pun.
        if (str_starts_with($filePath, '/')) {
            return $this->berkasTerbaca($filePath);
        }

        $dir = (string) config('telegram.api_dir', '');

        if ($dir === '') {
            return null;
        }

        /*
        | Susunan folder Local Bot API Server: <dir>/<token>/<file_path>.
        |
        | `file_path` datang dari Bot API server kita sendiri, bukan dari
        | pengguna. Tetap saja ia dipakai sebagai path, jadi ".." ditolak
        | mentah-mentah — penjagaan yang harganya satu baris, dan yang
        | ketiadaannya berarti satu jawaban API yang aneh bisa membaca berkas
        | mana pun yang terjangkau www-data.
        */

        if (str_contains($filePath, '..')) {
            return null;
        }

        return $this->berkasTerbaca($dir.'/'.$this->token().'/'.ltrim($filePath, '/'));
    }

    /** Path itu sendiri bila ia berkas biasa yang terbaca, atau null. */
    private function berkasTerbaca(string $path): ?string
    {
        return is_file($path) && is_readable($path) ? $path : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Pengiriman
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim satu permintaan, dengan pengulangan bila kegagalannya pantas
     * diulang.
     *
     * @param  array<string,SplFileInfo>  $files
     *
     * @throws TelegramException
     */
    protected function send(
        string $verb,
        string $method,
        array $payload,
        array $files = []
    ): TelegramResponse {

        if (! $this->isConfigured()) {

            $e = TelegramException::missingToken($method);

            $this->logException($e);

            throw $e;
        }

        $this->assertFilesSendable($method, $files);

        $maxAttempts = max(1, $this->retriesOverride ?? (int) config('telegram.retry.times', 3));

        $mulai = microtime(true);

        $attempt = 0;

        $this->log('info', 'request', [
            'method'   => $method,
            'verb'     => $verb,
            'files'    => array_keys($files),
        ] + $this->payloadContext($payload));

        while (true) {

            $attempt++;

            $terakhir = $attempt >= $maxAttempts;

            // Tahan lajunya sebelum Telegram yang menahan. Ini mengurangi
            // seberapa sering 429 terjadi; penanganan 429 di bawah tetap ada
            // karena pembatas ini bukan jaminan. Lihat TelegramRateLimiter.
            $this->limiter()->acquire();

            try {
                $response = $this->dispatch($verb, $method, $payload, $files);
            } catch (ConnectionException $e) {

                // Tidak ada jawaban sama sekali. Aman diulang untuk GET;
                // untuk POST lihat catatan idempoten di config/telegram.php.
                if (! $terakhir) {
                    $this->sleepMs($this->backoffMs($attempt));

                    continue;
                }

                $gagal = TelegramException::connectionFailed(
                    $method,
                    $this->redact($e->getMessage()),
                    $e::class,
                    $attempt
                );

                $this->logException($gagal, $mulai);

                throw $gagal;
            }

            $body = $this->decode($response);

            // Jawaban datang tapi bukan JSON Bot API. Bisa halaman galat
            // proxy; itu sering sementara, jadi tetap diulang.
            if ($body === null) {

                if (! $terakhir && $this->statusRetryable($response->status())) {
                    $this->sleepMs($this->backoffMs($attempt));

                    continue;
                }

                $gagal = TelegramException::invalidResponse(
                    $method,
                    $response->status(),
                    $this->redact(mb_substr(trim($response->body()), 0, 200))
                );

                $this->logException($gagal, $mulai);

                throw $gagal;
            }

            if (($body['ok'] ?? false) === true) {

                $hasil = new TelegramResponse(
                    method: $method,
                    result: $body['result'] ?? null,
                    httpStatus: $response->status(),
                    durationMs: $this->sinceMs($mulai),
                    attempts: $attempt,
                );

                $this->log('info', 'response', $hasil->logContext());

                return $hasil;
            }

            /*
            |------------------------------------------------------------------
            | Telegram menjawab ok: false
            |------------------------------------------------------------------
            */

            $errorCode = isset($body['error_code']) ? (int) $body['error_code'] : null;

            $description = (string) ($body['description'] ?? '');

            $retryAfter = isset($body['parameters']['retry_after'])
                ? (int) $body['parameters']['retry_after']
                : null;

            if (! $terakhir && ($jeda = $this->retryDelayMs($attempt, $errorCode, $response->status(), $retryAfter)) !== null) {

                $this->log('warning', 'retry', [
                    'method'      => $method,
                    'attempt'     => $attempt,
                    'error_code'  => $errorCode,
                    'description' => $description,
                    'sleep_ms'    => $jeda,
                ]);

                $this->sleepMs($jeda);

                continue;
            }

            $gagal = TelegramException::apiRejected(
                $method,
                $response->status(),
                $errorCode,
                $description,
                $retryAfter,
                $attempt
            );

            $this->logException($gagal, $mulai);

            throw $gagal;
        }
    }

    /**
     * Satu percobaan HTTP, tanpa penafsiran isi jawabannya.
     *
     * @param  array<string,SplFileInfo>  $files
     *
     * @throws ConnectionException
     */
    protected function dispatch(
        string $verb,
        string $method,
        array $payload,
        array $files
    ): Response {

        // Handle berkas dikumpulkan supaya bisa ditutup setelah permintaan
        // selesai. Tanpa ini, handle-nya menggantung sepanjang umur proses:
        // di php-fpm tidak terlihat karena prosesnya mati tiap request, tapi
        // di `queue:work` yang hidup berjam-jam, setiap video menambah satu
        // handle terbuka. Berkas sementara sudah di-unlink oleh pemanggil,
        // sehingga ruang disknya TIDAK dikembalikan selama handle terbuka --
        // 15 video berukuran ratusan MB pernah menahan 8 GB tanpa satu pun
        // berkas terlihat di `ls /tmp` atau terhitung oleh `du`.
        $handles = [];

        $request = $this->request($files, $handles);

        $url = $this->endpoint($method);

        try {
            if ($verb === 'GET') {
                return $request->get($url, $this->flatten($payload));
            }

            return $request->post(
                $url,
                $files === [] ? $this->clean($payload) : $this->flatten($payload)
            );
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * Permintaan dasar: batas waktu, dan lampiran berkas bila ada.
     *
     * Tanpa berkas, badan permintaan dikirim sebagai JSON — sehingga
     * `reply_markup` boleh berupa array PHP biasa dan Telegram membacanya
     * langsung. Dengan berkas, permintaannya multipart dan setiap nilai
     * harus jadi string; itu yang dikerjakan flatten().
     *
     * @param  array<string,SplFileInfo>  $files
     * @param  list<resource>  $handles Diisi dengan handle berkas yang dibuka,
     *                                  supaya pemanggil bisa menutupnya.
     */
    protected function request(array $files, array &$handles = []): PendingRequest
    {
        $request = Http::timeout($this->timeout())
            ->connectTimeout((int) config('telegram.connect_timeout', 5))
            ->withHeaders(['Accept' => 'application/json']);

        foreach ($files as $field => $file) {
            $handle = fopen($file->getPathname(), 'r');

            if ($handle === false) {
                throw new RuntimeException(
                    "Berkas {$file->getPathname()} tidak bisa dibuka untuk dikirim."
                );
            }

            $handles[] = $handle;

            $request = $request->attach(
                $field,
                $handle,
                $file->getFilename()
            );
        }

        return $request;
    }

    /*
    |--------------------------------------------------------------------------
    | Aturan pengulangan
    |--------------------------------------------------------------------------
    */

    /**
     * Berapa lama menunggu sebelum mencoba lagi, atau null bila kegagalan ini
     * tidak pantas diulang.
     *
     * 400, 401, dan 403 adalah keputusan tetap: token salah, pengguna
     * memblokir bot, chat tidak ada. Mengulangnya hanya menunda kegagalan
     * yang sudah pasti, dan pada broadcast ribuan penerima penundaan itu
     * berlipat jadi berjam-jam.
     */
    protected function retryDelayMs(
        int $attempt,
        ?int $errorCode,
        int $httpStatus,
        ?int $retryAfter
    ): ?int {

        $kode = $errorCode ?? $httpStatus;

        if ($kode === 429) {

            // Jeda dari Telegram, bukan tebakan kita. Bila terlalu lama,
            // menyerah — permintaan admin tidak boleh tertahan semenit.
            $maks = (int) config('telegram.retry.max_retry_after', 30);

            $detik = $retryAfter ?? 1;

            return $detik > $maks ? null : ($detik * 1000) + 250;
        }

        if ($this->statusRetryable($kode)) {
            return $this->backoffMs($attempt);
        }

        return null;
    }

    protected function statusRetryable(int $status): bool
    {
        return $status >= 500 && $status <= 599;
    }

    /** Backoff berganda, dibatasi max_sleep_ms. */
    protected function backoffMs(int $attempt): int
    {
        $dasar = (int) config('telegram.retry.sleep_ms', 400);

        $maks = (int) config('telegram.retry.max_sleep_ms', 5000);

        return min($maks, $dasar * (2 ** ($attempt - 1)));
    }

    protected function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Berkas
    |--------------------------------------------------------------------------
    */

    /**
     * Tolak berkas yang sudah pasti ditolak Telegram, sebelum dikirim.
     *
     * Mengirim berkas 300 MB melalui jaringan hanya untuk ditolak di akhir
     * membuang waktu dan kuota VPS, dan pada koneksi lambat kegagalannya
     * muncul sebagai timeout — gejala yang menyesatkan.
     *
     * @param  array<string,SplFileInfo>  $files
     *
     * @throws TelegramException
     */
    protected function assertFilesSendable(string $method, array $files): void
    {
        if ($files === []) {
            return;
        }

        $maxBytes = ((int) config('telegram.upload_max_mb', 50)) * 1048576;

        foreach ($files as $file) {

            $path = $file->getPathname();

            if (! is_file($path) || ! is_readable($path)) {

                $e = TelegramException::fileUnreadable($method, $path);

                $this->logException($e);

                throw $e;
            }

            $bytes = (int) filesize($path);

            if ($bytes > $maxBytes) {

                $e = TelegramException::fileTooLarge(
                    $method,
                    $file->getFilename(),
                    $bytes,
                    $maxBytes
                );

                $this->logException($e);

                throw $e;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Bentuk payload
    |--------------------------------------------------------------------------
    */

    /**
     * Buang nilai null, biarkan sisanya apa adanya.
     *
     * Null berarti "tidak disebut". Mengirimkannya membuat Telegram menolak
     * sebagian method dengan galat parsing, padahal maksudnya justru
     * memakai bawaan.
     */
    protected function clean(array $payload): array
    {
        return array_filter($payload, fn ($v) => $v !== null);
    }

    /**
     * Ubah setiap nilai jadi string, untuk badan multipart dan query GET.
     *
     * Array (reply_markup, entities) jadi JSON; boolean jadi "true"/"false",
     * bukan "1"/"" — Telegram menolak yang terakhir.
     */
    protected function flatten(array $payload): array
    {
        $hasil = [];

        foreach ($this->clean($payload) as $key => $value) {

            $hasil[$key] = match (true) {
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
                is_bool($value)  => $value ? 'true' : 'false',
                default          => (string) $value,
            };
        }

        return $hasil;
    }

    /** Isi jawaban sebagai array, atau null bila bukan JSON Bot API. */
    protected function decode(Response $response): ?array
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($body) || ! array_key_exists('ok', $body)) {
            return null;
        }

        return $body;
    }

    /*
    |--------------------------------------------------------------------------
    | Alamat dan token
    |--------------------------------------------------------------------------
    */

    /**
     * Pembatas laju, diambil dari container saat dibutuhkan.
     *
     * Tidak disuntik lewat constructor supaya tanda tangan client tidak
     * berubah — ia sudah dipasang sebagai singleton di AppServiceProvider
     * sejak 8.1, dan menambah parameter di sana berarti setiap tiruan di
     * pengujian ikut harus diperbarui.
     */
    protected function limiter(): TelegramRateLimiter
    {
        return app(TelegramRateLimiter::class);
    }

    protected function token(): string
    {
        return (string) config('telegram.bot_token');
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('telegram.api_url', 'https://api.telegram.org'), '/');
    }

    protected function endpoint(string $method): string
    {
        return $this->baseUrl().'/bot'.$this->token().'/'.$method;
    }

    protected function timeout(): int
    {
        return $this->timeoutOverride ?? (int) config('telegram.timeout', 15);
    }

    /*
    |--------------------------------------------------------------------------
    | Log
    |--------------------------------------------------------------------------
    */

    /**
     * Ganti token bot dengan penanda.
     *
     * Token ada di dalam URL setiap permintaan, dan URL muncul apa adanya di
     * pesan galat Guzzle. Tanpa langkah ini, satu gangguan jaringan sudah
     * cukup untuk menuliskan token bot ke laravel.log — berkas yang dibaca,
     * disalin, dan dikirimkan saat menelusuri masalah.
     */
    public function redact(?string $text): string
    {
        $text = (string) $text;

        $token = $this->token();

        if ($token !== '') {
            $text = str_replace($token, '<token>', $text);
        }

        // Jaring pengaman: pola token Bot API di mana pun ia muncul,
        // termasuk token lama yang tertinggal di pesan yang di-cache.
        return (string) preg_replace('/\b\d{6,12}:[A-Za-z0-9_-]{30,}/', '<token>', $text);
    }

    /**
     * Konteks payload untuk log.
     *
     * Isi pesan adalah tulisan pengguna, jadi tidak ikut kecuali
     * log_payload dinyalakan — dan meski dinyalakan, teksnya dipotong.
     * Yang selalu ikut hanyalah tujuan dan panjang, cukup untuk melacak
     * pesan tanpa menyimpan isinya.
     */
    protected function payloadContext(array $payload): array
    {
        $context = [];

        foreach (['chat_id', 'message_id', 'callback_query_id', 'file_id'] as $key) {
            if (isset($payload[$key])) {
                $context[$key] = $payload[$key];
            }
        }

        foreach (['text', 'caption'] as $key) {

            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                continue;
            }

            $context[$key.'_length'] = mb_strlen($payload[$key]);

            if (config('telegram.logging.log_payload', false)) {
                $context[$key] = mb_substr(
                    $this->redact($payload[$key]),
                    0,
                    (int) config('telegram.logging.text_limit', 120)
                );
            }
        }

        return $context;
    }

    protected function logException(TelegramException $e, ?float $mulai = null): void
    {
        $this->log('error', 'failed', $e->logContext() + [
            'message'     => $e->getMessage(),
            'duration_ms' => $mulai === null ? null : $this->sinceMs($mulai),
        ]);
    }

    /**
     * Tulis ke log Laravel.
     *
     * Konteks dibangun hanya dari field yang sudah disaring di atas, jadi
     * token dan isi pesan tidak bisa lolos lewat sini. Pola yang sama dipakai
     * StorageEngine.
     */
    protected function log(string $level, string $event, array $context): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->log($level, 'telegram.'.$event, $context);
    }

    protected function sinceMs(float $mulai): int
    {
        return (int) round((microtime(true) - $mulai) * 1000);
    }
}
