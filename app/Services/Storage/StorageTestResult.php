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
            'duration_ms' => $this->durationMs,
            'exception'   => $this->exceptionClass,
        ];
    }
}
