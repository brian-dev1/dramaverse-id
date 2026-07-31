<?php

namespace App\Enums;

/**
 * Keadaan satu langganan.
 *
 * PENDING ada karena langganan dibuat SEBELUM pembayarannya lunas — itu yang
 * membuat invoice bisa menunjuk langganan mana yang akan diaktifkan, dan
 * membuat pengguna bisa melihat "menunggu pembayaran" di riwayatnya alih-alih
 * tidak melihat apa pun setelah menekan tombol bayar.
 */
enum SubscriptionStatus: string
{
    case PENDING = 'pending';

    case ACTIVE = 'active';

    case EXPIRED = 'expired';

    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Menunggu pembayaran',
            self::ACTIVE    => 'Aktif',
            self::EXPIRED   => 'Kedaluwarsa',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::ACTIVE    => 'badge-on',
            self::PENDING   => 'badge-pending',
            default         => 'badge-off',
        };
    }

    /** Sedang memberi akses premium. */
    public function grantsAccess(): bool
    {
        return $this === self::ACTIVE;
    }

    public static function options(): array
    {
        $hasil = [];

        foreach (self::cases() as $case) {
            $hasil[$case->value] = $case->label();
        }

        return $hasil;
    }
}
