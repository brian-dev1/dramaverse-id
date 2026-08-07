<?php

namespace App\Support;

/**
 * Verifikasi initData dari Telegram Mini App (Telegram WebApp).
 *
 * Telegram menandatangani seluruh parameter dengan HMAC-SHA256 memakai
 * kunci turunan dari bot token. Selama tanda tangannya cocok, data user
 * di dalamnya boleh dipercaya — tidak perlu token sekali pakai lagi.
 *
 * Referensi: https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class TelegramInitData
{
    /**
     * @return array{user:array,auth_date:int,start_param:?string}|null
     */
    public static function validate(string $initData, ?string $botToken = null, int $ttl = 86400): ?array
    {
        $botToken = $botToken ?: (string) config('telegram.bot_token');

        if ($botToken === '' || trim($initData) === '') {
            return null;
        }

        parse_str($initData, $data);

        $hash = $data['hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        unset($data['hash'], $data['signature']);
        ksort($data);

        $pairs = [];

        foreach ($data as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $check  = hash_hmac('sha256', implode("\n", $pairs), $secret);

        if (! hash_equals($check, $hash)) {
            return null;
        }

        $authDate = (int) ($data['auth_date'] ?? 0);

        // initData lama ditolak supaya tidak bisa diputar ulang.
        if ($ttl > 0 && ($authDate <= 0 || (time() - $authDate) > $ttl)) {
            return null;
        }

        $user = isset($data['user']) ? json_decode((string) $data['user'], true) : null;

        if (! is_array($user) || ! isset($user['id'])) {
            return null;
        }

        return [
            'user'        => $user,
            'auth_date'   => $authDate,
            'start_param' => isset($data['start_param']) ? (string) $data['start_param'] : null,
        ];
    }
}
