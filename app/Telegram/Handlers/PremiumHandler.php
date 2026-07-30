<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\MembershipService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;

/**
 * Keadaan membership pengguna, dan paket yang tersedia.
 *
 * Halaman ini tidak menjual apa pun — pembayaran berada di luar scope Phase 8
 * dan tetap dikerjakan di website. Yang dilakukan bot adalah menjelaskan
 * keadaan sekarang dengan jujur, lalu mengantar ke website.
 */
class PremiumHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected MembershipService $membership
    ) {
    }

    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        $baris = ['💎 <b>Premium</b>', ''];

        $baris[] = $this->statusLine($user);

        $baris[] = '';

        foreach ($this->membership->plans() as $paket) {

            $harga = is_numeric($paket->price ?? null)
                ? 'Rp '.number_format((float) $paket->price, 0, ',', '.')
                : null;

            $baris[] = '• <b>'.e($paket->name ?? '-').'</b>'
                .($harga ? ' — '.$harga : '')
                .(isset($paket->duration) ? ' / '.e((string) $paket->duration).' hari' : '');
        }

        $baris[] = '';
        $baris[] = 'Berlangganan dilakukan di website. Tekan tombol di bawah untuk masuk.';

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $baris),
            ['reply_markup' => ['inline_keyboard' => [[[
                'text'          => '🌐 Buka Website',
                'callback_data' => 'website',
            ]]]]]
        );
    }

    /**
     * Tiga keadaan yang dibedakan spesifikasi: Free, Premium, dan Expired.
     *
     * Expired sengaja tidak disamakan dengan Free. Pengguna yang langganannya
     * habis perlu tahu bahwa ia PERNAH punya akses dan kapan berakhirnya —
     * memberitahunya "Anda pengguna gratis" seolah tidak pernah berlangganan
     * adalah jawaban yang terasa salah bagi orang yang baru saja membayar.
     */
    private function statusLine(?User $user): string
    {
        if ($user === null) {
            return 'Kirim /start dulu supaya akun Anda dikenali.';
        }

        if (! $user->is_premium) {
            return 'Status Anda: <b>Gratis</b>. Episode premium belum bisa dibuka.';
        }

        $berakhir = $user->premium_expired_at;

        if ($berakhir !== null && now()->gte($berakhir)) {
            return 'Status Anda: <b>Kedaluwarsa</b> sejak '
                .$berakhir->format('d M Y').'. Perpanjang untuk membuka episode premium lagi.';
        }

        return 'Status Anda: <b>Premium aktif</b>'
            .($berakhir !== null ? ' sampai '.$berakhir->format('d M Y') : ' tanpa batas waktu').'.';
    }
}
