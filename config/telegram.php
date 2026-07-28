<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot
    |--------------------------------------------------------------------------
    */

    'bot_token'    => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

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

    'login_token_ttl' => (int) env('TELEGRAM_LOGIN_TOKEN_TTL', 10),

    'api_url' => 'https://api.telegram.org',

];
