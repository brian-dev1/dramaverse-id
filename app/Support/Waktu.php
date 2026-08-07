<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Satu tempat untuk memformat waktu di seluruh aplikasi.
 *
 * ## Kenapa perlu
 *
 * Sebelum ini setiap tempat memformat sendiri: `d M Y` di satu blade,
 * `d M Y H:i` di blade lain, `translatedFormat('d F Y')` di tempat ketiga.
 * Akibatnya satu tagihan yang sama terbaca berbeda tergantung dibuka dari
 * mana — dan yang paling sering hilang justru JAM-nya, padahal tenggat
 * pembayaran dan berakhirnya masa VIP itu urusan jam, bukan urusan hari.
 * "Bayar sebelum 7 Agustus" tidak menjawab pertanyaan "sebelum jam berapa".
 *
 * ## Zona waktu
 *
 * Basis data menyimpan UTC. Yang dilihat pengguna WAJIB waktu lokal beserta
 * labelnya. Tanpa label, pengguna di Makassar dan pengguna di Jakarta membaca
 * angka yang sama dan menyimpulkan dua tenggat yang berbeda.
 *
 * Zona diambil dari `config('app.display_timezone')` — lihat config/app.php.
 */
final class Waktu
{
    /** Peta singkatan zona yang dipakai Indonesia. */
    private const LABEL = [
        'Asia/Jakarta'  => 'WIB',
        'Asia/Pontianak' => 'WIB',
        'Asia/Makassar' => 'WITA',
        'Asia/Jayapura' => 'WIT',
        'UTC'           => 'UTC',
    ];

    public static function zona(): string
    {
        return (string) config('app.display_timezone', 'Asia/Jakarta');
    }

    public static function label(?string $zona = null): string
    {
        $zona ??= self::zona();

        return self::LABEL[$zona] ?? Carbon::now($zona)->format('T');
    }

    /** Pindahkan ke zona tampilan. Null tetap null. */
    public static function lokal(CarbonInterface|string|null $waktu): ?Carbon
    {
        if ($waktu === null || $waktu === '') {
            return null;
        }

        return Carbon::parse($waktu)->setTimezone(self::zona());
    }

    /**
     * Bentuk terlengkap: hari, tanggal, bulan, tahun, jam, menit, zona.
     *
     * Contoh: "Jumat, 07 Agustus 2026 pukul 21.30 WIB"
     *
     * Dipakai di detail tagihan, bukti bayar, dan pesan berakhirnya VIP —
     * tempat-tempat yang jadi rujukan kalau ada sengketa.
     */
    public static function lengkap(CarbonInterface|string|null $waktu, string $kosong = '—'): string
    {
        $t = self::lokal($waktu);

        if ($t === null) {
            return $kosong;
        }

        return $t->translatedFormat('l, d F Y').' pukul '.$t->format('H.i').' '.self::label();
    }

    /**
     * Bentuk ringkas tapi tetap lengkap unsurnya: tanggal + jam + zona.
     *
     * Contoh: "07 Agu 2026, 21.30 WIB"
     *
     * Untuk tabel dan daftar, di mana bentuk panjang membuat kolom melar.
     */
    public static function ringkas(CarbonInterface|string|null $waktu, string $kosong = '—'): string
    {
        $t = self::lokal($waktu);

        if ($t === null) {
            return $kosong;
        }

        return $t->translatedFormat('d M Y').', '.$t->format('H.i').' '.self::label();
    }

    /**
     * Tanggal saja: "07 Agu 2026".
     *
     * Dipakai HANYA untuk hal yang memang tidak punya jam bermakna — tanggal
     * bergabung, rentang laporan, label kolom sempit. Untuk tenggat dan masa
     * berlaku, pakai `ringkas()` atau `lengkap()`: di sana jam justru bagian
     * yang paling ditanyakan.
     *
     * Tetap lewat `lokal()`, bukan `format()` langsung. Tanggal pun bisa
     * meleset satu hari kalau dicetak dari UTC — apa saja yang tersimpan
     * antara pukul 00.00 dan 07.00 WIB jatuh di tanggal sebelumnya menurut
     * UTC, dan itu persis jam-jam ramai bot ini.
     */
    public static function tanggal(CarbonInterface|string|null $waktu, string $kosong = '—'): string
    {
        $t = self::lokal($waktu);

        return $t === null ? $kosong : $t->translatedFormat('d M Y');
    }

    /** Bulan dan tahun saja: "Agustus 2026". */
    public static function bulan(CarbonInterface|string|null $waktu, string $kosong = '—'): string
    {
        $t = self::lokal($waktu);

        return $t === null ? $kosong : $t->translatedFormat('F Y');
    }

    /**
     * Bentuk untuk mesin: "2026-08-07 21:30:00 +07:00".
     *
     * Dipakai di ekspor CSV dan atribut `title=` pada HTML, supaya nilai yang
     * bisa disalin selalu tidak ambigu.
     */
    public static function presisi(CarbonInterface|string|null $waktu, string $kosong = ''): string
    {
        $t = self::lokal($waktu);

        return $t === null ? $kosong : $t->format('Y-m-d H:i:s P');
    }

    /**
     * Selisih yang enak dibaca: "3 hari 4 jam lagi" / "berakhir 2 jam lalu".
     *
     * Dipakai berdampingan dengan tanggal absolut, TIDAK menggantikannya.
     * "2 hari lagi" saja membuat orang menunda tanpa tahu jam berapa batasnya.
     */
    public static function relatif(CarbonInterface|string|null $waktu, string $kosong = '—'): string
    {
        $t = self::lokal($waktu);

        if ($t === null) {
            return $kosong;
        }

        return $t->diffForHumans(Carbon::now(self::zona()), [
            'parts'  => 2,
            'short'  => false,
        ]);
    }

    /**
     * Tanggal + jam + hitung mundur sekaligus, satu baris.
     *
     * Contoh: "Jumat, 07 Agustus 2026 pukul 21.30 WIB (2 hari 4 jam lagi)"
     */
    public static function lengkapRelatif(CarbonInterface|string|null $waktu, string $kosong = '—'): string
    {
        if (self::lokal($waktu) === null) {
            return $kosong;
        }

        return self::lengkap($waktu).' ('.self::relatif($waktu).')';
    }

    /** Durasi hari menjadi kalimat: 30 -> "30 hari (1 bulan)". */
    public static function durasi(?int $hari): string
    {
        $hari = (int) $hari;

        if ($hari <= 0) {
            return '—';
        }

        if ($hari % 365 === 0) {
            return $hari.' hari ('.intdiv($hari, 365).' tahun)';
        }

        if ($hari % 30 === 0) {
            return $hari.' hari ('.intdiv($hari, 30).' bulan)';
        }

        return $hari.' hari';
    }
}
