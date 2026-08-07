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

        // parse_str() tidak dipakai: fungsi itu mengubah nama field yang
        // mengandung titik atau spasi, dan menerjemahkan '+' menjadi spasi.
        // Data-check-string harus persis seperti yang dikirim Telegram.
        $data = [];

        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $data[rawurldecode($key)] = rawurldecode($value);
        }

        $hash = $data['hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        unset($data['hash']);
        ksort($data);

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        // "Chain of all received fields" — hanya `hash` yang dikeluarkan.
        // `signature` (Bot API 7.10) ikut ditandatangani, jadi harus tetap
        // ada di sini. Varian tanpa `signature` dicoba sebagai cadangan
        // untuk klien lama yang belum mengirim field tersebut.
        $cocok = false;

        foreach ([$data, array_diff_key($data, ['signature' => true])] as $kandidat) {
            $pairs = [];

            foreach ($kandidat as $key => $value) {
                $pairs[] = $key.'='.$value;
            }

            if (hash_equals(hash_hmac('sha256', implode("\n", $pairs), $secret), $hash)) {
                $cocok = true;
                break;
            }
        }

        if (! $cocok) {
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
