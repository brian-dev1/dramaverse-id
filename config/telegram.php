<?php

/*
|--------------------------------------------------------------------------
| Membaca angka dari .env dengan aman
|--------------------------------------------------------------------------
|
| `TELEGRAM_TIMEOUT=` (baris ada, nilainya kosong) menghasilkan string
| kosong, BUKAN nilai yang belum diset — sehingga argumen default env()
| tidak berlaku dan (int) '' menjadi 0. Untuk timeout, nol berarti
| "tunggu selamanya": satu permintaan admin bisa menggantung tanpa batas.
|
| Penjagaan yang sama sudah dipakai config/storage.php pada allowed_drivers,
| karena persoalannya identik.
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
    | Bot
    |--------------------------------------------------------------------------
    |
    | Token didapat dari @BotFather. Bentuknya `<angka>:<huruf dan angka>`.
    | Token ini rahasia: siapa pun yang memilikinya bisa mengirim pesan atas
    | nama bot. Karena itu ia TIDAK BOLEH muncul di log maupun di pesan galat
    | — lihat TelegramClient::redact().
    |
    | bot_username dipakai untuk menyusun tautan t.me, bukan untuk memanggil
    | API. Isinya tanpa tanda @.
    |
    */

    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    /*
    |--------------------------------------------------------------------------
    | Alamat API
    |--------------------------------------------------------------------------
    |
    | Bawaannya server resmi Telegram. Bisa diarahkan ke Local Bot API Server
    | sendiri, yang menaikkan batas unggah dari 50 MB ke 2000 MB — relevan
    | nanti saat video episode dikirim lewat Telegram, tapi belum dipakai
    | sekarang.
    |
    | Tanpa garis miring di akhir. Nilai kosong di .env jatuh ke bawaan.
    |
    */

    'api_url' => rtrim(env('TELEGRAM_API_URL') ?: 'https://api.telegram.org', '/'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Rahasia ini dikirim Telegram pada header X-Telegram-Bot-Api-Secret-Token
    | dan dipakai untuk memastikan permintaan benar-benar berasal dari Telegram.
    |
    */

    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Tautan masuk
    |--------------------------------------------------------------------------
    |
    | Masa berlaku token sekali pakai yang dikirim bot ke pengguna (menit).
    |
    */

    'login_token_ttl' => $angka('TELEGRAM_LOGIN_TOKEN_TTL', 10, 1),

    /*
    |--------------------------------------------------------------------------
    | Batas waktu
    |--------------------------------------------------------------------------
    |
    | timeout          — seluruh permintaan, termasuk mengunggah berkas
    | connect_timeout  — hanya sampai koneksi TCP/TLS terbentuk
    |
    | Dua-duanya perlu. Server yang mati menolak koneksi dengan cepat, tetapi
    | server yang menggantung (jaringan VPS bermasalah, DNS lambat) hanya
    | tertangkap oleh connect_timeout yang lebih pendek.
    |
    | 15 detik cukup untuk sendMessage. Pengiriman berkas besar nanti perlu
    | angka lebih tinggi — itu dilewatkan per-permintaan lewat
    | TelegramService::withTimeout(), bukan dengan menaikkan angka ini,
    | supaya satu unggahan lambat tidak membuat semua permintaan lain ikut
    | menunggu lama saat Telegram sedang bermasalah.
    |
    */

    'timeout' => $angka('TELEGRAM_TIMEOUT', 15, 1),

    'connect_timeout' => $angka('TELEGRAM_CONNECT_TIMEOUT', 5, 1),

    /*
    |--------------------------------------------------------------------------
    | Percobaan ulang
    |--------------------------------------------------------------------------
    |
    | Hanya kegagalan yang masuk akal diulang yang diulang:
    |
    |   - koneksi gagal atau habis waktu
    |   - HTTP 5xx (Telegram sedang bermasalah)
    |   - HTTP 429 (kena batas laju) — jedanya memakai `retry_after` dari
    |     Telegram, bukan backoff kita
    |
    | Yang TIDAK diulang: 400, 401, 403. Itu keputusan tetap dari Telegram
    | (token salah, pengguna memblokir bot, chat_id tidak ada). Mengulangnya
    | hanya memperlambat kegagalan yang sudah pasti.
    |
    | PERINGATAN JUJUR: Bot API tidak punya kunci idempoten. Kalau pesan
    | benar-benar sampai lalu koneksi putus sebelum jawabannya kembali,
    | percobaan ulang mengirim pesan yang sama dua kali. Itu harga yang
    | dipilih di sini — pesan ganda lebih ringan daripada pesan yang hilang
    | diam-diam. Setel TELEGRAM_RETRY_TIMES=1 untuk mematikannya.
    |
    | times = jumlah percobaan TOTAL, bukan jumlah pengulangan. 1 berarti
    | sekali coba tanpa ulang.
    |
    | max_retry_after menjaga permintaan admin tidak tertahan lama: kalau
    | Telegram minta menunggu lebih lama dari ini, kita menyerah dan
    | melaporkannya, bukan tidur di dalam request.
    |
    */

    'retry' => [

        'times' => $angka('TELEGRAM_RETRY_TIMES', 3, 1),

        'sleep_ms' => $angka('TELEGRAM_RETRY_SLEEP_MS', 400, 0),

        'max_sleep_ms' => $angka('TELEGRAM_RETRY_MAX_SLEEP_MS', 5000, 0),

        'max_retry_after' => $angka('TELEGRAM_RETRY_MAX_WAIT', 30, 0),

    ],

    /*
    |--------------------------------------------------------------------------
    | Log
    |--------------------------------------------------------------------------
    |
    | Setiap panggilan menulis satu baris berawalan `telegram.`. Channel
    | kosong berarti memakai channel bawaan aplikasi.
    |
    | log_payload mati secara bawaan. Isi pesan Telegram adalah tulisan
    | pengguna dan bisa memuat hal pribadi; menaruhnya di log berarti
    | menyimpannya di tempat yang aturan penyimpanannya berbeda. Nyalakan
    | hanya saat sedang menelusuri masalah, lalu matikan lagi. Meski
    | dinyalakan, teks dipotong pada text_limit dan token tetap diredaksi.
    |
    */

    'logging' => [

        'enabled' => $boolean('TELEGRAM_LOGGING', true),

        'channel' => env('TELEGRAM_LOG_CHANNEL'),

        'log_payload' => $boolean('TELEGRAM_LOG_PAYLOAD', false),

        'text_limit' => $angka('TELEGRAM_LOG_TEXT_LIMIT', 120, 0),

    ],

    /*
    |--------------------------------------------------------------------------
    | Bawaan pesan
    |--------------------------------------------------------------------------
    |
    | HTML dipilih daripada MarkdownV2 karena MarkdownV2 mewajibkan
    | pelolosan pada belasan karakter biasa (titik, tanda hubung, tanda seru).
    | Judul drama memuat karakter-karakter itu, dan satu yang terlewat
    | membuat Telegram menolak seluruh pesan dengan galat parsing.
    |
    */

    'parse_mode' => env('TELEGRAM_PARSE_MODE') ?: 'HTML',

    /*
    |--------------------------------------------------------------------------
    | Batas unggah Bot API
    |--------------------------------------------------------------------------
    |
    | Telegram menolak berkas di atas 50 MB lewat server resmi. Diperiksa di
    | sisi kita supaya berkas 300 MB tidak dikirim penuh lewat jaringan
    | hanya untuk ditolak di akhir.
    |
    | Naikkan hanya bila api_url diarahkan ke Local Bot API Server sendiri.
    |
    */

    'upload_max_mb' => $angka('TELEGRAM_UPLOAD_MAX_MB', 50, 1),

    /*
    |--------------------------------------------------------------------------
    | Chat penyimpanan
    |--------------------------------------------------------------------------
    |
    | Video episode dikirim SEKALI ke chat ini untuk mendapatkan `file_id`,
    | lalu setiap pengiriman ke pengguna memakai file_id itu. Sesudah sinkron
    | pertama, tidak ada byte video yang keluar dari server kita lagi.
    |
    | Isinya id channel atau grup privat tempat bot menjadi admin. Bentuknya
    | angka negatif berawalan -100 untuk supergroup dan channel.
    |
    | Chat ini TIDAK boleh publik: isinya seluruh katalog video, termasuk yang
    | berbayar, dan siapa pun yang bisa membukanya bisa menontonnya tanpa
    | melewati pemeriksaan membership.
    |
    */

    'storage_chat_id' => env('TELEGRAM_STORAGE_CHAT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Sinkronisasi video
    |--------------------------------------------------------------------------
    |
    | timeout — pengiriman berkas ratusan megabyte jelas tidak selesai dalam
    | 15 detik seperti sendMessage. Angka ini hanya dipakai job sinkronisasi,
    | lewat TelegramService::withTimeout().
    |
    | max_retry — batas percobaan otomatis. Sesudah ini video tetap bisa
    | diulang manual dari panel; batasnya ada supaya berkas yang memang
    | terlalu besar tidak dicoba selamanya.
    |
    */

    'sync' => [

        'timeout' => $angka('TELEGRAM_SYNC_TIMEOUT', 1800, 60),

        'max_retry' => $angka('TELEGRAM_SYNC_MAX_RETRY', 3, 1),

        'queue' => env('TELEGRAM_SYNC_QUEUE') ?: 'default',

    ],

    /*
    |--------------------------------------------------------------------------
    | Daftar episode di bot
    |--------------------------------------------------------------------------
    |
    | Telegram membatasi tinggi inline keyboard, dan daftar 200 episode dalam
    | satu pesan tidak bisa dibaca siapa pun. Dipecah per halaman.
    |
    */

    'episode_page_size' => $angka('TELEGRAM_EPISODE_PAGE_SIZE', 20, 4),

];
