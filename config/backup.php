<?php

$angka = function (string $key, int $default, int $min = 1): int {

    $nilai = env($key);

    if ($nilai === null || $nilai === '' || ! is_numeric($nilai)) {
        return $default;
    }

    return max($min, (int) $nilai);
};

return [

    /*
    |--------------------------------------------------------------------------
    | Berapa cadangan yang disimpan
    |--------------------------------------------------------------------------
    |
    | Cadangan tersimpan di disk VPS yang sama dengan aplikasinya, jadi
    | jumlahnya dibatasi. Tujuh berarti seminggu penuh bila dijalankan harian.
    |
    | PERINGATAN: cadangan yang hanya ada di server yang sama dengan
    | aplikasinya bukan cadangan yang sesungguhnya. Ia melindungi dari
    | kesalahan manusia — tabel yang terhapus, migration yang salah — tetapi
    | tidak dari server yang hilang. Salin keluar server secara berkala.
    |
    */

    'keep' => $angka('BACKUP_KEEP', 7),

    /*
    |--------------------------------------------------------------------------
    | Umur maksimal sebelum dianggap basi
    |--------------------------------------------------------------------------
    |
    | Dashboard monitoring menandai merah bila cadangan terbaru lebih tua dari
    | ini. 26 jam, bukan 24: jadwal harian yang bergeser sedikit tidak boleh
    | memicu peringatan palsu setiap hari.
    |
    */

    'max_age_hours' => $angka('BACKUP_MAX_AGE_HOURS', 26),

    /*
    |--------------------------------------------------------------------------
    | Verifikasi otomatis
    |--------------------------------------------------------------------------
    |
    | Cadangan diperiksa tepat setelah dibuat. Cadangan yang tidak pernah
    | diperiksa bukan cadangan — ia baru diketahui rusak pada satu-satunya
    | kali ia dibutuhkan.
    |
    | Pemeriksaannya membongkar daftar isi arsip tanpa menuliskannya, jadi
    | murah. Matikan hanya bila arsipnya sangat besar dan servernya sibuk.
    |
    */

    'verify_after_create' => filter_var(
        env('BACKUP_VERIFY', true),
        FILTER_VALIDATE_BOOL
    ),

];
