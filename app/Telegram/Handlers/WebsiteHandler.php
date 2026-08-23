<?php

namespace App\Telegram\Handlers;

use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;

class WebsiteHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected UserRepository $users,
        protected LoginService $login
    ) {
    }

    public function handle(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];
        $telegramId = $callback['from']['id'];

        $this->kirim($chatId, $this->users->findByTelegramId($telegramId));
    }

    /**
     * Kirim cara masuk ke website.
     *
     * Dipisahkan dari `handle()` supaya bisa dipanggil dari tempat yang tidak
     * punya objek callback — khususnya deep link `?start=login`, yang dipakai
     * tombol "Masuk lewat Telegram" di website. Tanpa pemisahan ini, satu-
     * satunya cara mendapatkan tautan masuk adalah menekan tombol di dalam
     * menu bot, dan orang yang sedang berdiri di halaman web tidak punya cara
     * menemukannya.
     *
     * @param  \App\Models\User|null  $user
     */
    public function kirim(int|string $chatId, $user): void
    {
        if (! $user) {

            // Dikirim sebagai pesan, bukan sebagai jawaban callback.
            // CallbackHandler sudah menjawab callback-nya lebih dulu, dan
            // Telegram hanya menerima satu jawaban per penekanan tombol —
            // yang kedua ditolak, dan pemberitahuannya tidak sampai.
            $this->telegram->sendMessage(
                $chatId,
                'Kirim /start dulu supaya akun Anda dikenali.'
            );

            return;
        }

        $miniAppUrl = rtrim((string) (config('telegram.miniapp_url') ?: config('app.url')), '/').'/';

        // Mini App hanya boleh HTTPS. Kalau belum, jatuh kembali ke tautan
        // login sekali pakai supaya fitur ini tetap jalan.
        if (str_starts_with($miniAppUrl, 'https://')) {
            $this->telegram->sendMessage(
                $chatId,
                implode("\n", [
                    "🌐 *Aplikasi DramaVerse*",
                    "",
                    "Buka langsung di dalam Telegram — akun Anda otomatis masuk.",
                ]),
                [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[[
                            'text'    => (string) setting('bot_app_button', (string) config('telegram.miniapp_button_text', 'Buka Aplikasi')),
                            'web_app' => ['url' => $miniAppUrl],
                        ]]],
                    ]),
                ]
            );

            return;
        }

        $token = $this->login->generate($user);

        $minutes = (int) config('telegram.login_token_ttl', 10);

        $url = url('/auth/telegram/' . $token);

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", [
                "🌐 *Aplikasi DramaVerse*",
                "",
                "Klik link di bawah untuk login otomatis ke aplikasi.",
                "",
                $url,
                "",
                "⏳ Link hanya berlaku selama {$minutes} menit dan sekali pakai."
            ]),
            [
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]
        );
    }
}