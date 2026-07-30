<?php

namespace App\Enums;

/**
 * Keadaan satu pekerjaan unggah di antrean.
 *
 * Spesifikasi sprint menyebut empat: Pending, Processing, Success, Failed.
 * Yang kelima — `CANCELLED` — ditambahkan karena spesifikasi yang sama juga
 * meminta "Cancel Upload sebelum diproses", dan pembatalan harus punya
 * keadaannya sendiri.
 *
 * Menandai pekerjaan yang dibatalkan sebagai `FAILED` akan mencampur dua hal
 * yang berbeda: kegagalan perlu ditelusuri dan diulang, sedangkan pembatalan
 * adalah keputusan sadar admin dan tidak perlu ditindaklanjuti siapa pun.
 * Kalau keduanya disatukan, daftar "yang gagal" akan penuh baris yang
 * sebenarnya baik-baik saja, dan yang benar-benar gagal jadi tenggelam.
 *
 * Perpindahan yang sah — tidak ada yang lain:
 *
 *   PENDING    -> PROCESSING   (worker mengambilnya)
 *   PENDING    -> CANCELLED    (admin membatalkan sebelum diambil)
 *   PROCESSING -> SUCCESS
 *   PROCESSING -> FAILED
 *   FAILED     -> PENDING      (admin menekan Retry)
 *
 * `PROCESSING` tidak bisa dibatalkan. Berkasnya sedang dikirim ke provider,
 * dan menghentikannya di tengah jalan meninggalkan objek separuh di bucket
 * yang tidak dikenali baris mana pun.
 */
enum UploadStatus: string
{
    case PENDING = 'pending';

    case PROCESSING = 'processing';

    case SUCCESS = 'success';

    case FAILED = 'failed';

    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING    => 'Menunggu',
            self::PROCESSING => 'Diproses',
            self::SUCCESS    => 'Berhasil',
            self::FAILED     => 'Gagal',
            self::CANCELLED  => 'Dibatalkan',
        };
    }

    /**
     * Kelas badge di tabel panel.
     *
     * Dipetakan di sini, bukan di Blade, supaya menambah satu case enum tidak
     * meninggalkan badge tanpa warna di halaman yang tidak ikut disunting.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING    => 'badge-pending',
            self::PROCESSING => 'badge-processing',
            self::SUCCESS    => 'badge-on',
            self::FAILED     => 'badge-off',
            self::CANCELLED  => 'badge-cancelled',
        };
    }

    /**
     * Keadaan akhir: tidak akan berubah lagi tanpa tindakan admin.
     *
     * Dipakai polling di peramban untuk berhenti bertanya.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::PENDING, self::PROCESSING          => false,
            self::SUCCESS, self::FAILED, self::CANCELLED => true,
        };
    }

    /** Hanya yang belum diambil worker yang boleh dibatalkan. */
    public function canCancel(): bool
    {
        return $this === self::PENDING;
    }

    /** Hanya yang gagal yang boleh diulang. */
    public function canRetry(): bool
    {
        return $this === self::FAILED;
    }

    /**
     * Berkas staging boleh dihapus.
     *
     * `FAILED` sengaja TIDAK termasuk: berkas staging adalah satu-satunya
     * salinan yang tersisa, dan menghapusnya membuat tombol Retry kehilangan
     * bahan. Admin yang memang tidak ingin mengulang bisa menghapus barisnya.
     */
    public function releasesStagedFile(): bool
    {
        return match ($this) {
            self::SUCCESS, self::CANCELLED           => true,
            self::PENDING, self::PROCESSING, self::FAILED => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
