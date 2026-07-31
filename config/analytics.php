<?php

$angka = function (string $key, int $default, int $min = 0): int {

    $nilai = env($key);

    if ($nilai === null || $nilai === '' || ! is_numeric($nilai)) {
        return $default;
    }

    return max($min, (int) $nilai);
};

$boolean = function (string $key, bool $default): bool {

    $nilai = env($key);

    if ($nilai === null || $nilai === '') {
        return $default;
    }

    return filter_var($nilai, FILTER_VALIDATE_BOOL);
};

return [

    /*
    |--------------------------------------------------------------------------
    | Cache dashboard
    |--------------------------------------------------------------------------
    |
    | Yang di-cache adalah SEKSI utuh, bukan tiap angka: satu kunci untuk
    | seluruh kartu dan grafik di satu tab.
    |
    | TTL pendek dengan sengaja. Angka analitik yang telat lima menit tidak
    | merugikan siapa pun; yang telat satu jam membuat orang berhenti
    | memercayai dashboard-nya, dan dashboard yang tidak dipercaya sama saja
    | dengan tidak ada.
    |
    | Matikan hanya saat menelusuri selisih angka. Dashboard tanpa cache
    | menjalankan belasan query agregat setiap kali dibuka.
    |
    */

    'cache' => [

        'enabled' => $boolean('ANALYTICS_CACHE', true),

        'ttl' => $angka('ANALYTICS_CACHE_TTL', 300, 30),

    ],

    /*
    |--------------------------------------------------------------------------
    | Laporan
    |--------------------------------------------------------------------------
    |
    | max_rows membatasi berapa baris yang boleh masuk satu ekspor. Tanpa
    | batas, ekspor setahun penuh membaca seluruh tabel ke memori dan
    | menghentikan proses PHP di tengah unduhan -- yang muncul ke admin
    | sebagai berkas rusak, bukan sebagai pesan galat.
    |
    | preview_rows adalah yang ditampilkan di layar sebelum diekspor.
    |
    */

    'report' => [

        'max_rows' => $angka('ANALYTICS_REPORT_MAX_ROWS', 20000, 100),

        'preview_rows' => $angka('ANALYTICS_REPORT_PREVIEW', 100, 10),

    ],

];
