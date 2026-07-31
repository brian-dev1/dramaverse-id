<?php

namespace App\Enums;

/**
 * Keadaan satu transaksi pembayaran.
 *
 * ## Perpindahan status dijaga, bukan dipercaya
 *
 * Callback datang dari luar dan bisa datang berkali-kali, terlambat, atau
 * berurutan terbalik — provider mengirim ulang callback yang gagal, dan
 * jaringan tidak menjamin urutannya. Tanpa penjagaan, callback `pending` yang
 * datang terlambat bisa mengembalikan transaksi yang sudah PAID jadi PENDING,
 * dan membership yang sudah aktif ikut dicabut.
 *
 * `canTransitionTo()` menutup itu: keadaan akhir tidak bisa mundur.
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';

    case PAID = 'paid';

    case FAILED = 'failed';

    case EXPIRED = 'expired';

    case CANCELLED = 'cancelled';

    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Menunggu pembayaran',
            self::PAID      => 'Lunas',
            self::FAILED    => 'Gagal',
            self::EXPIRED   => 'Kedaluwarsa',
            self::CANCELLED => 'Dibatalkan',
            self::REFUNDED  => 'Dikembalikan',
        };
    }

    /** Kelas badge, memakai kosakata CSS yang sudah ada di panel. */
    public function badge(): string
    {
        return match ($this) {
            self::PENDING   => 'badge-pending',
            self::PAID      => 'badge-on',
            self::REFUNDED  => 'badge-processing',
            default         => 'badge-off',
        };
    }

    /**
     * Keadaan yang tidak berubah lagi dengan sendirinya.
     *
     * REFUNDED tidak masuk: ia justru datang SETELAH PAID, dan itu satu-satunya
     * perpindahan yang boleh terjadi dari keadaan akhir.
     */
    public function isFinal(): bool
    {
        return $this !== self::PENDING;
    }

    /** Pembayaran diterima. */
    public function isPaid(): bool
    {
        return $this === self::PAID || $this === self::REFUNDED;
    }

    /**
     * Boleh berpindah ke status ini.
     *
     * Aturannya sederhana dan sengaja ketat:
     *   - dari PENDING boleh ke mana saja
     *   - dari PAID hanya boleh ke REFUNDED
     *   - keadaan akhir lain tidak berpindah ke mana pun
     *   - status yang sama selalu boleh (callback ganda bukan kesalahan)
     */
    public function canTransitionTo(self $tujuan): bool
    {
        if ($this === $tujuan) {
            return true;
        }

        return match ($this) {
            self::PENDING => true,
            self::PAID    => $tujuan === self::REFUNDED,
            default       => false,
        };
    }

    /** @return array<string,string> untuk dropdown penyaring */
    public static function options(): array
    {
        $hasil = [];

        foreach (self::cases() as $case) {
            $hasil[$case->value] = $case->label();
        }

        return $hasil;
    }
}
