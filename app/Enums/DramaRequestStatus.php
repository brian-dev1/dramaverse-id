<?php

namespace App\Enums;

/**
 * Perjalanan sebuah permintaan drama.
 *
 * Empat keadaan, dan tidak lebih. Setiap status tambahan berarti satu kalimat
 * lagi yang harus dijelaskan ke pengguna, dan pengguna yang meminta drama
 * hanya ingin tahu satu hal: sudah ada belum.
 *
 * `REJECTED` ada karena diamnya admin adalah jawaban terburuk. Permintaan yang
 * tidak akan pernah dipenuhi — filmnya tidak ada subtitle, atau sudah pernah
 * ditolak karena alasan lain — lebih baik dinyatakan daripada dibiarkan
 * menggantung di "menunggu" selamanya.
 */
enum DramaRequestStatus: string
{
    case PENDING   = 'pending';

    case PROCESS   = 'process';

    case AVAILABLE = 'available';

    case REJECTED  = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Menunggu ditinjau',
            self::PROCESS   => 'Sedang diproses',
            self::AVAILABLE => 'Sudah tersedia',
            self::REJECTED  => 'Tidak bisa dipenuhi',
        };
    }

    /** Kalimat untuk pengguna, menjelaskan artinya bagi dia. */
    public function keterangan(): string
    {
        return match ($this) {
            self::PENDING   => 'Permintaan Anda sudah masuk dan menunggu ditinjau admin.',
            self::PROCESS   => 'Admin sedang mengusahakan drama ini. Anda akan diberi tahu begitu tersedia.',
            self::AVAILABLE => 'Dramanya sudah bisa ditonton.',
            self::REJECTED  => 'Maaf, permintaan ini belum bisa kami penuhi.',
        };
    }

    /** Kelas badge di panel admin dan halaman pengguna. */
    public function badge(): string
    {
        return match ($this) {
            self::PENDING   => 'badge-pending',
            self::PROCESS   => 'badge-pending',
            self::AVAILABLE => 'badge-on',
            self::REJECTED  => 'badge-off',
        };
    }

    /** Status yang sudah selesai — tidak menunggu apa pun lagi. */
    public function selesai(): bool
    {
        return $this === self::AVAILABLE || $this === self::REJECTED;
    }

    /** @return array<string,string> nilai => label, untuk dropdown. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
