<?php

namespace App\Services\Storage;

use Throwable;

/**
 * Hasil satu kali Test Connection.
 *
 * Objek nilai, sengaja dibuat tidak bisa diubah. Test Connection tidak
 * pernah melempar exception ke pemanggil — kegagalan adalah hasil yang sah
 * dan harus bisa ditampilkan di panel admin, bukan halaman error 500.
 */
class StorageTestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?float $durationMs = null,
        public readonly ?string $exceptionClass = null,
    ) {
    }

    public static function pass(
        string $message,
        ?float $durationMs = null
    ): self {

        return new self(true, $message, $durationMs);
    }

    public static function fail(
        string $message,
        ?float $durationMs = null,
        ?Throwable $exception = null
    ): self {

        return new self(
            false,
            $message,
            $durationMs,
            $exception ? $exception::class : null
        );
    }

    /**
     * Dari exception apa pun. Pesan aslinya dipakai apa adanya — pesan
     * SDK penyimpanan biasanya sudah menyebut sebab yang tepat, dan
     * merangkumnya justru menghilangkan petunjuk.
     */
    public static function fromException(
        Throwable $exception,
        ?float $durationMs = null
    ): self {

        return self::fail(
            $exception->getMessage() ?: $exception::class,
            $durationMs,
            $exception
        );
    }

    public function failed(): bool
    {
        return ! $this->success;
    }

    /**
     * Nilai untuk kolom last_test_status.
     */
    public function status(): string
    {
        return $this->success ? 'ok' : 'failed';
    }

    /**
     * Terjemahan galat penyimpanan ke penyebab yang paling mungkin.
     *
     * Galat dari SDK S3 sering menunjuk ke tempat yang salah. Contoh nyata
     * dari server ini: endpoint R2 yang belum diganti menghasilkan "SSL
     * routines::sslv3 alert handshake failure" — pesan yang membuat orang
     * memeriksa sertifikat dan firewall, padahal host-nya memang tidak ada.
     *
     * Daftar ini tidak menggantikan pesan asli, hanya menemaninya. Pesan
     * aslinya tetap disimpan apa adanya karena kadang justru di situ
     * petunjuk yang menentukan.
     */
    public function hint(): ?string
    {
        if ($this->success) {
            return null;
        }

        $m = $this->message;

        $has = fn (string ...$needles) => array_reduce(
            $needles,
            fn ($carry, $needle) => $carry || stripos($m, $needle) !== false,
            false
        );

        return match (true) {

            $has('handshake failure', 'cURL error 35', 'certificate') =>
                'Host tujuan kemungkinan tidak ada atau salah tulis. Periksa '
                .'kolom endpoint — placeholder yang belum diganti '
                .'(mis. ACCOUNT_ID) menghasilkan galat TLS seperti ini, '
                .'bukan galat "host tidak ditemukan" yang lebih jelas.',

            $has('Could not resolve host', 'cURL error 6') =>
                'DNS tidak mengenali host di kolom endpoint. Periksa ejaannya.',

            $has('Failed to connect', 'Connection refused', 'cURL error 7') =>
                'Host terjawab tapi koneksi ditolak. Periksa port dan firewall '
                .'— untuk MinIO, pastikan endpoint memuat port yang benar.',

            $has('cURL error 28', 'timed out') =>
                'Batas waktu terlampaui. Naikkan STORAGE_TIMEOUT, atau periksa '
                .'apakah VPS bisa keluar ke internet.',

            $has('SignatureDoesNotMatch') =>
                'Secret key salah, atau region tidak cocok dengan bucket. '
                .'Untuk R2 region harus `auto`.',

            $has('InvalidAccessKeyId') =>
                'Access key tidak dikenali. Pastikan token disalin utuh dan '
                .'belum dicabut.',

            $has('NoSuchBucket') =>
                'Bucket tidak ditemukan. Periksa nama bucket dan pastikan '
                .'ia berada di akun yang sama dengan kuncinya.',

            $has('PermanentRedirect', 'AuthorizationHeaderMalformed') =>
                'Region atau endpoint tidak cocok dengan lokasi bucket.',

            $has('AccessDenied', '403 Forbidden') =>
                'Kredensial dikenali tetapi izinnya kurang. Token butuh izin '
                .'baca DAN tulis objek — Test Connection menulis, membaca, '
                .'lalu menghapus satu berkas kecil.',

            default => null,
        };
    }

    /**
     * Durasi yang enak dibaca di panel admin.
     */
    public function durationForHumans(): ?string
    {
        if ($this->durationMs === null) {
            return null;
        }

        return $this->durationMs < 1000
            ? round($this->durationMs).' ms'
            : round($this->durationMs / 1000, 2).' s';
    }

    public function toArray(): array
    {
        return [
            'success'     => $this->success,
            'status'      => $this->status(),
            'message'     => $this->message,
            'hint'        => $this->hint(),
            'duration_ms' => $this->durationMs,
            'exception'   => $this->exceptionClass,
        ];
    }
}
