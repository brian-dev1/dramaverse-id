<?php

/*
|--------------------------------------------------------------------------
| Membaca nilai .env dengan aman
|--------------------------------------------------------------------------
|
| Pola yang sama dengan config/telegram.php dan config/storage.php: baris
| yang ADA tapi kosong menghasilkan string kosong, bukan nilai yang belum
| diset, sehingga argumen default env() tidak berlaku. Untuk masa berlaku
| tagihan, nol berarti setiap tagihan kedaluwarsa seketika.
|
*/

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
    | Mata uang
    |--------------------------------------------------------------------------
    |
    | Disimpan per invoice, bukan dibaca ulang saat menampilkan. Tagihan lama
    | harus tetap menunjukkan mata uang saat ia dibuat.
    |
    */

    'currency' => env('PAYMENT_CURRENCY') ?: 'IDR',

    /*
    |--------------------------------------------------------------------------
    | Masa berlaku tagihan
    |--------------------------------------------------------------------------
    |
    | Dalam menit. Sesudah lewat, tagihan dikedaluwarsakan scheduler dan tidak
    | bisa dibayar lagi.
    |
    | 24 jam dipilih supaya orang yang memutuskan membayar besok pagi tidak
    | kehilangan tagihannya, tetapi harga tidak digantung terlalu lama —
    | tagihan menggantung berminggu-minggu membuat angka pendapatan tidak bisa
    | dipercaya dan mengunci harga lama setelah harganya naik.
    |
    */

    'invoice_ttl' => $angka('PAYMENT_INVOICE_TTL', 1440, 5),

    /*
    |--------------------------------------------------------------------------
    | Verifikasi ulang
    |--------------------------------------------------------------------------
    |
    | Callback bisa tidak pernah sampai — jaringan tidak menjamin apa pun.
    | Scheduler menanyakan sendiri keadaan transaksi yang masih menunggu.
    |
    | max_attempts membatasi supaya transaksi yang memang tidak akan pernah
    | dibayar tidak ditanyakan selamanya. Sesudah batasnya, yang menutupnya
    | adalah kedaluwarsa tagihan.
    |
    */

    'verify' => [

        'max_attempts' => $angka('PAYMENT_VERIFY_MAX_ATTEMPTS', 12, 1),

        'batch' => $angka('PAYMENT_VERIFY_BATCH', 50, 1),

        'queue' => env('PAYMENT_QUEUE') ?: 'default',

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache status membership
    |--------------------------------------------------------------------------
    |
    | Dalam detik. Dibaca di setiap halaman berbayar dan setiap permintaan
    | menonton lewat bot.
    |
    | Pendek dengan sengaja: yang paling buruk dari cache ini adalah membership
    | yang sudah habis masih terbaca aktif. Lima menit adalah selisih yang bisa
    | diterima, dan setiap perubahan tetap membuang cache-nya secara eksplisit.
    |
    */

    'membership_cache_ttl' => $angka('PAYMENT_MEMBERSHIP_CACHE_TTL', 300, 0),

    /*
    |--------------------------------------------------------------------------
    | Pencegahan penyalahgunaan
    |--------------------------------------------------------------------------
    |
    | max_pending_invoices — satu pengguna tidak boleh punya lebih banyak
    | tagihan menggantung daripada ini. Tanpa batas, satu skrip bisa membuat
    | ribuan tagihan dalam semenit dan memenuhi tabel sekaligus mengacaukan
    | seluruh angka pendapatan.
    |
    | callback_rate — batas permintaan callback per menit per IP. Endpoint
    | callback terbuka ke internet dan tidak bisa diberi CSRF; ini satu-satunya
    | penahan sebelum verifikasi tanda tangan.
    |
    */

    'guard' => [

        'max_pending_invoices' => $angka('PAYMENT_MAX_PENDING', 3, 1),

        'callback_rate' => $angka('PAYMENT_CALLBACK_RATE', 60, 1),

    ],

    /*
    |--------------------------------------------------------------------------
    | Log
    |--------------------------------------------------------------------------
    |
    | Setiap peristiwa menulis satu baris berawalan `payment.`.
    |
    | Isi payload callback TIDAK ikut secara utuh: ia memuat nama, email, dan
    | kadang nomor kartu yang tersamar. Yang dicatat hanya kunci-kuncinya dan
    | nilai yang memang perlu untuk menelusuri — nomor invoice, referensi,
    | status, nominal.
    |
    */

    'logging' => [

        'enabled' => $boolean('PAYMENT_LOGGING', true),

        'channel' => env('PAYMENT_LOG_CHANNEL'),

    ],

];
