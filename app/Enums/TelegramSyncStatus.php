<?php

namespace App\Enums;

/**
 * Keadaan sinkronisasi satu video episode ke Telegram.
 *
 * Sinkronisasi di sini berarti: berkas yang sudah ada di storage provider
 * dikirim SEKALI ke Telegram, lalu `file_id` yang dikembalikan disimpan.
 * Sesudah itu setiap pengiriman ke pengguna memakai `file_id` tersebut —
 * tidak ada byte video yang keluar dari server kita lagi.
 */
enum TelegramSyncStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SYNCED = 'synced';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING    => 'Menunggu',
            self::PROCESSING => 'Diproses',
            self::SYNCED     => 'Tersinkron',
            self::FAILED     => 'Gagal',
        };
    }

    /** Kelas badge, memakai kosakata CSS yang sudah ada di panel. */
    public function badge(): string
    {
        return match ($this) {
            self::PENDING    => 'badge-pending',
            self::PROCESSING => 'badge-processing',
            self::SYNCED     => 'badge-on',
            self::FAILED     => 'badge-off',
        };
    }

    /**
     * Boleh dimulai sinkronisasinya.
     *
     * PROCESSING ditolak supaya satu video tidak dikirim dua kali karena
     * tombol Sync ditekan berulang sementara job pertama masih berjalan.
     * SYNCED juga ditolak — mengirim ulang berkas yang sudah ada di Telegram
     * hanya menghabiskan kuota dan menghasilkan `file_id` kedua untuk isi
     * yang sama.
     */
    public function canStart(): bool
    {
        return $this === self::PENDING || $this === self::FAILED;
    }
}
