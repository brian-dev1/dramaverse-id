<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */
    'video_worker' => [
    'token' => env('VIDEO_WORKER_TOKEN'),
    ],
    
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | Sengaja TIDAK ada di sini. Seluruh konfigurasi Telegram ada di
    | config/telegram.php, satu tempat saja.
    |
    | Sebelum Sprint 8.1 kunci ini ada di dua berkas sekaligus, dan
    | TelegramRepository membaca `services.telegram.bot_token` sementara
    | seluruh kode lain membaca `telegram.bot_token`. Keduanya kebetulan
    | menunjuk env yang sama, jadi tidak pernah terlihat salah — sampai ada
    | yang mengganti sumber token di satu berkas saja, dan sebagian jalur
    | pengiriman diam-diam memakai token kosong.
    |
    */

];