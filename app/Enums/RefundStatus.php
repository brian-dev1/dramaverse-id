<?php

namespace App\Enums;

/**
 * Keadaan pengembalian dana.
 *
 * Struktur datanya disiapkan penuh, alurnya **belum** — spesifikasi Phase 10
 * menyebut "Refund Status (struktur data)", bukan pengembalian dana yang
 * berjalan. Kolomnya ada, enum-nya ada, dan panel menampilkannya; yang belum
 * ada adalah yang memanggil API pengembalian dana milik provider.
 *
 * Dipisahkan dari PaymentStatus karena keduanya bergerak sendiri-sendiri:
 * transaksi tetap PAID sementara pengembaliannya sedang diproses, dan
 * menggabungkannya berarti kehilangan salah satu dari dua fakta itu.
 */
enum RefundStatus: string
{
    case NONE = 'none';

    case REQUESTED = 'requested';

    case PROCESSING = 'processing';

    case REFUNDED = 'refunded';

    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NONE       => 'Tidak ada',
            self::REQUESTED  => 'Diminta',
            self::PROCESSING => 'Diproses',
            self::REFUNDED   => 'Dikembalikan',
            self::REJECTED   => 'Ditolak',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::REFUNDED   => 'badge-on',
            self::REJECTED   => 'badge-off',
            self::NONE       => 'badge-status',
            default          => 'badge-pending',
        };
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
