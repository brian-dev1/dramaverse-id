<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * Satu-satunya kegagalan yang dilempar lapisan Telegram.
 *
 * Pemanggil hanya perlu menangkap satu jenis, lalu bertanya pada objeknya
 * apa yang sebenarnya terjadi — bukan mencocokkan potongan kata di dalam
 * pesan galat. Sebelum sprint ini, `SendTelegramBroadcast` melakukan
 * `str_contains($description, 'blocked')`, dan cara itu ikut salah begitu
 * Telegram mengubah kalimatnya.
 *
 * ## Token tidak pernah ada di sini
 *
 * Token bot ada di dalam URL setiap permintaan. Pesan galat Guzzle memuat
 * URL itu apa adanya. Karena itu:
 *
 *   - pesan exception ini selalu sudah diredaksi oleh TelegramClient;
 *   - exception Guzzle **tidak** dipasang sebagai `previous`.
 *
 * Yang kedua itu pengorbanan yang disengaja. Melampirkan `previous` memberi
 * jejak tumpukan yang lebih kaya, tetapi Laravel menuliskan seluruh rantai
 * exception ke `laravel.log` saat ada yang tidak tertangkap — dan pada saat
 * itu token bot ikut tercetak di berkas log produksi. Kelas exception asal
 * dan pesannya yang sudah diredaksi disimpan sebagai gantinya, dan itu sudah
 * cukup untuk mengenali penyebabnya.
 *
 * Setiap pesan menyebut apa yang salah DAN langkah berikutnya, mengikuti
 * kebiasaan StorageEngineException.
 */
class TelegramException extends RuntimeException
{
    /** Nama method Bot API yang gagal, misalnya `sendMessage`. */
    public ?string $method = null;

    /** Kode status HTTP. Nol bila permintaan tidak pernah sampai. */
    public int $httpStatus = 0;

    /** `error_code` dari Telegram. Null bila jawabannya bukan dari Telegram. */
    public ?int $errorCode = null;

    /** `description` dari Telegram, apa adanya. */
    public ?string $description = null;

    /** Detik yang diminta Telegram sebelum mencoba lagi (khusus 429). */
    public ?int $retryAfter = null;

    /** Berapa kali permintaan dicoba sebelum menyerah. */
    public int $attempts = 1;

    /** Gagal karena konfigurasi, bukan karena jaringan atau Telegram. */
    public bool $configProblem = false;

    /*
    |--------------------------------------------------------------------------
    | Konfigurasi
    |--------------------------------------------------------------------------
    */

    public static function missingToken(string $method): self
    {
        $e = new self(
            "Panggilan Telegram `{$method}` dibatalkan: TELEGRAM_BOT_TOKEN "
            .'belum diisi. Ambil token dari @BotFather, isikan di .env, lalu '
            .'jalankan `php artisan config:cache`.'
        );

        $e->method = $method;

        $e->configProblem = true;

        return $e;
    }

    /*
    |--------------------------------------------------------------------------
    | Jaringan
    |--------------------------------------------------------------------------
    */

    /**
     * Permintaan tidak pernah mendapat jawaban.
     *
     * $redactedMessage sudah dibersihkan dari token oleh TelegramClient.
     */
    public static function connectionFailed(
        string $method,
        string $redactedMessage,
        string $previousClass,
        int $attempts
    ): self {

        $e = new self(sprintf(
            'Tidak bisa menghubungi Telegram untuk `%s` setelah %d percobaan: '
            .'%s (%s). Periksa koneksi keluar server ke api.telegram.org — '
            .'firewall VPS dan DNS adalah dua penyebab yang paling sering.',
            $method,
            $attempts,
            $redactedMessage ?: 'tanpa keterangan',
            $previousClass
        ));

        $e->method = $method;

        $e->attempts = $attempts;

        return $e;
    }

    /**
     * Jawaban datang, tapi bukan JSON Bot API yang bisa dibaca.
     *
     * Biasanya berarti permintaan tidak sampai ke Telegram sama sekali —
     * halaman galat proxy, portal jaringan, atau api_url yang salah arah.
     */
    public static function invalidResponse(
        string $method,
        int $httpStatus,
        string $redactedBody
    ): self {

        $e = new self(sprintf(
            'Jawaban untuk `%s` bukan JSON Bot API (HTTP %d). Potongan '
            .'jawabannya: %s. Periksa TELEGRAM_API_URL dan apakah ada proxy '
            .'yang menyisipkan halamannya sendiri.',
            $method,
            $httpStatus,
            $redactedBody === '' ? 'kosong' : '"'.$redactedBody.'"'
        ));

        $e->method = $method;

        $e->httpStatus = $httpStatus;

        return $e;
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram menolak
    |--------------------------------------------------------------------------
    */

    public static function apiRejected(
        string $method,
        int $httpStatus,
        ?int $errorCode,
        string $description,
        ?int $retryAfter,
        int $attempts
    ): self {

        $e = new self(sprintf(
            'Telegram menolak `%s` (HTTP %d, error_code %s): %s',
            $method,
            $httpStatus,
            $errorCode === null ? '-' : $errorCode,
            $description ?: 'tanpa keterangan'
        ));

        $e->method = $method;

        $e->httpStatus = $httpStatus;

        $e->errorCode = $errorCode;

        $e->description = $description;

        $e->retryAfter = $retryAfter;

        $e->attempts = $attempts;

        return $e;
    }

    /*
    |--------------------------------------------------------------------------
    | Berkas
    |--------------------------------------------------------------------------
    */

    public static function fileUnreadable(string $method, string $path): self
    {
        $e = new self(
            "Berkas untuk `{$method}` tidak bisa dibaca: {$path}. Periksa "
            .'keberadaan dan izin berkasnya sebelum dikirim ke Telegram.'
        );

        $e->method = $method;

        return $e;
    }

    /**
     * Ditolak di sisi kita, sebelum satu byte pun dikirim.
     */
    public static function fileTooLarge(
        string $method,
        string $name,
        int $bytes,
        int $maxBytes
    ): self {

        $e = new self(sprintf(
            'Berkas %s berukuran %s MB, melewati batas unggah Bot API yaitu '
            .'%s MB, jadi `%s` dibatalkan sebelum dikirim. Perkecil berkasnya, '
            .'atau arahkan TELEGRAM_API_URL ke Local Bot API Server sendiri '
            .'yang batasnya 2000 MB.',
            $name,
            number_format($bytes / 1048576, 1),
            number_format($maxBytes / 1048576, 0),
            $method
        ));

        $e->method = $method;

        return $e;
    }

    /*
    |--------------------------------------------------------------------------
    | Pertanyaan yang biasa diajukan pemanggil
    |--------------------------------------------------------------------------
    |
    | Dicocokkan pada error_code lebih dulu, lalu pada kata kunci description.
    | Telegram memakai 403 untuk beberapa keadaan berbeda, jadi kode saja
    | tidak cukup — tapi kata kunci saja juga tidak cukup, karena kalimatnya
    | pernah berubah. Keduanya dipakai bersama.
    |
    */

    /** Pengguna memblokir bot, atau akunnya dihapus. Jangan dicoba lagi. */
    public function isBlockedByUser(): bool
    {
        if ($this->errorCode !== 403) {
            return false;
        }

        $d = strtolower((string) $this->description);

        return str_contains($d, 'blocked')
            || str_contains($d, 'user is deactivated')
            || str_contains($d, 'bot was kicked');
    }

    /** chat_id tidak dikenal — pengguna belum pernah membuka bot. */
    public function isChatNotFound(): bool
    {
        $d = strtolower((string) $this->description);

        return str_contains($d, 'chat not found')
            || str_contains($d, 'user not found');
    }

    /** Kena batas laju. `retryAfter` berisi jeda yang diminta Telegram. */
    public function isRateLimited(): bool
    {
        return $this->errorCode === 429 || $this->httpStatus === 429;
    }

    /**
     * Token salah, dicabut, atau belum diisi.
     *
     * Telegram menjawab 401 untuk token yang ditolak dan 404 untuk token yang
     * bentuknya tidak dikenal sama sekali — 404 di sini bukan "method tidak
     * ada", karena nama method disusun kode kita, bukan masukan pengguna.
     */
    public function isTokenProblem(): bool
    {
        if ($this->configProblem) {
            return true;
        }

        return in_array($this->errorCode, [401, 404], true)
            || in_array($this->httpStatus, [401, 404], true);
    }

    /** Permintaan tidak pernah mendapat jawaban. */
    public function isConnectionProblem(): bool
    {
        return ! $this->configProblem
            && $this->httpStatus === 0
            && $this->errorCode === null;
    }

    /**
     * Saran langkah berikutnya untuk operator, atau null bila tidak ada yang
     * bisa disarankan selain membaca pesannya.
     */
    public function hint(): ?string
    {
        if ($this->isTokenProblem()) {
            return 'Token bot ditolak Telegram. Cocokkan TELEGRAM_BOT_TOKEN '
                .'dengan yang ada di @BotFather, lalu `php artisan config:cache`.';
        }

        if ($this->isBlockedByUser()) {
            return 'Pengguna memblokir bot. Tidak ada yang bisa diperbaiki dari '
                .'sisi server — hentikan pengiriman ke chat ini.';
        }

        if ($this->isChatNotFound()) {
            return 'Pengguna belum pernah menekan Start di bot. Bot tidak boleh '
                .'memulai percakapan lebih dulu; pengguna harus membuka bot sekali.';
        }

        if ($this->isRateLimited()) {
            return sprintf(
                'Kena batas laju Telegram%s. Kirim lewat antrean dengan jeda, '
                .'jangan berurutan dalam satu permintaan.',
                $this->retryAfter ? ", diminta menunggu {$this->retryAfter} detik" : ''
            );
        }

        if ($this->isConnectionProblem()) {
            return 'Uji dari VPS: `curl -sS -o /dev/null -w "%{http_code}\n" '
                .'https://api.telegram.org`. Bila gagal, persoalannya jaringan '
                .'server, bukan kode.';
        }

        return null;
    }

    /** Konteks siap-log. Tidak memuat token maupun isi pesan. */
    public function logContext(): array
    {
        return [
            'method'      => $this->method,
            'http_status' => $this->httpStatus,
            'error_code'  => $this->errorCode,
            'description' => $this->description,
            'retry_after' => $this->retryAfter,
            'attempts'    => $this->attempts,
        ];
    }
}
