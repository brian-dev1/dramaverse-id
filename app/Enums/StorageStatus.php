<?php

namespace App\Enums;

/**
 * Status ketersediaan sebuah storage provider.
 *
 * `INACTIVE` adalah status awal yang sengaja dipilih: provider baru tidak
 * boleh langsung menerima lalu lintas sebelum Test Connection berhasil.
 */
enum StorageStatus: string
{
    case ACTIVE = 'active';

    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE   => 'Aktif',
            self::INACTIVE => 'Nonaktif',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
