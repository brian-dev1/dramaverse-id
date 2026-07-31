<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Rentang waktu untuk seluruh laporan dan grafik.
 *
 * Satu definisi untuk empat pertanyaan yang selalu ditanyakan bersamaan:
 * rentangnya sampai mana, dikelompokkan per apa, berapa titik yang dibuat,
 * dan bagaimana labelnya ditulis.
 *
 * Menyebarkannya ke masing-masing pemanggil berarti "bulanan" bisa berarti
 * 12 bulan di satu grafik dan 6 di grafik sebelahnya, di halaman yang sama.
 */
enum AnalyticsPeriod: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';

    public function label(): string
    {
        return match ($this) {
            self::DAY   => 'Harian',
            self::WEEK  => 'Mingguan',
            self::MONTH => 'Bulanan',
            self::YEAR  => 'Tahunan',
        };
    }

    /** Jumlah titik yang ditampilkan di grafik. */
    public function points(): int
    {
        return match ($this) {
            self::DAY   => 30,
            self::WEEK  => 12,
            self::MONTH => 12,
            self::YEAR  => 5,
        };
    }

    /**
     * Format pengelompokan di SQL.
     *
     * `DATE_FORMAT` MySQL. Mingguan memakai `%x-%v` — tahun dan minggu ISO,
     * bukan `%Y-%u`: minggu pertama Januari sering masih milik tahun
     * sebelumnya menurut ISO, dan memakai `%Y` menghasilkan dua kelompok
     * berbeda untuk minggu yang sama.
     */
    public function sqlFormat(): string
    {
        return match ($this) {
            self::DAY   => '%Y-%m-%d',
            self::WEEK  => '%x-%v',
            self::MONTH => '%Y-%m',
            self::YEAR  => '%Y',
        };
    }

    /** Awal rentang, dihitung mundur dari sekarang. */
    public function since(): Carbon
    {
        $n = $this->points();

        return match ($this) {
            self::DAY   => now()->subDays($n - 1)->startOfDay(),
            self::WEEK  => now()->subWeeks($n - 1)->startOfWeek(),
            self::MONTH => now()->subMonths($n - 1)->startOfMonth(),
            self::YEAR  => now()->subYears($n - 1)->startOfYear(),
        };
    }

    /**
     * Seluruh kunci kelompok dalam rentang, urut.
     *
     * Ini yang membuat grafik punya titik nol untuk hari tanpa transaksi.
     * Tanpa daftar lengkap, grafik hanya menampilkan hari yang kebetulan ada
     * datanya — dan garis yang melompati hari kosong menyembunyikan justru
     * hal yang paling ingin dilihat: kapan berhentinya.
     *
     * @return array<int,string>
     */
    public function buckets(): array
    {
        $kunci = [];

        $kursor = $this->since();

        for ($i = 0; $i < $this->points(); $i++) {

            $kunci[] = $this->keyFor($kursor);

            $kursor = match ($this) {
                self::DAY   => $kursor->addDay(),
                self::WEEK  => $kursor->addWeek(),
                self::MONTH => $kursor->addMonth(),
                self::YEAR  => $kursor->addYear(),
            };
        }

        return $kunci;
    }

    /** Kunci kelompok untuk satu tanggal — harus cocok dengan sqlFormat(). */
    public function keyFor(Carbon $tanggal): string
    {
        return match ($this) {
            self::DAY   => $tanggal->format('Y-m-d'),
            self::WEEK  => $tanggal->isoFormat('GGGG-WW'),
            self::MONTH => $tanggal->format('Y-m'),
            self::YEAR  => $tanggal->format('Y'),
        };
    }

    /** Label yang dibaca manusia untuk satu kunci kelompok. */
    public function labelFor(string $kunci): string
    {
        return match ($this) {
            self::DAY   => Carbon::createFromFormat('Y-m-d', $kunci)->translatedFormat('d M'),
            self::WEEK  => 'Mg '.substr($kunci, -2),
            self::MONTH => Carbon::createFromFormat('Y-m', $kunci)->translatedFormat('M Y'),
            self::YEAR  => $kunci,
        };
    }

    public static function fromRequest(?string $nilai): self
    {
        return self::tryFrom((string) $nilai) ?? self::MONTH;
    }

    /** @return array<string,string> untuk dropdown */
    public static function options(): array
    {
        $hasil = [];

        foreach (self::cases() as $case) {
            $hasil[$case->value] = $case->label();
        }

        return $hasil;
    }
}
