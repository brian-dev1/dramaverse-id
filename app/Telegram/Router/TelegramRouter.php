<?php

namespace App\Telegram\Router;

use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\UserSessionService;
use App\Enums\TelegramMenuAction;
use App\Telegram\Handlers\CallbackHandler;
use App\Telegram\Handlers\PaymentProofHandler;
use App\Telegram\Handlers\PremiumHandler;
use App\Telegram\Handlers\SearchHandler;
use App\Telegram\Handlers\StartHandler;
use Illuminate\Support\Facades\Log;

class TelegramRouter
{
    public function __construct(
        protected UserSessionService $sessions,
        protected TelegramRepositoryInterface $users
    ) {
    }

    public function dispatch(array $update): void
    {
        /*
        |--------------------------------------------------------------------------
        | Callback Query
        |--------------------------------------------------------------------------
        */

        if (isset($update['callback_query'])) {

            app(CallbackHandler::class)
                ->handle($update['callback_query']);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */

        if (! isset($update['message'])) {

            /*
            |------------------------------------------------------------------
            | Update yang tidak ditangani
            |------------------------------------------------------------------
            |
            | Sebelumnya dibuang tanpa jejak. Dicatat sekarang karena satu
            | jenisnya justru dibutuhkan operator: `channel_post` datang setiap
            | kali ada pesan di channel tempat bot jadi admin, dan di dalamnya
            | ada id channel itu — nilai yang harus diisikan ke
            | TELEGRAM_STORAGE_CHAT_ID dan yang tidak terbaca dari mana pun
            | selain dari sini.
            |
            | Isi pesannya TIDAK dicatat. Yang dicatat hanya jenis update,
            | id chat, dan judulnya.
            |
            */

            $this->logUnhandled($update);

            return;
        }

        $message = $update['message'];

        $chatId = $message['chat']['id'];

        $text = trim($message['text'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | START COMMAND
        |--------------------------------------------------------------------------
        */

        if (str_starts_with($text, '/start')) {

            app(StartHandler::class)
                ->handle($message);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Conversation State
        |--------------------------------------------------------------------------
        |
        | State disimpan dengan id pengguna di basis data kita, BUKAN
        | telegram_id. Sebelum ini `$message['from']['id']` dipakai apa adanya,
        | sehingga pencarian state selalu meleset dan balasan pencarian tidak
        | pernah datang — tanpa satu pun galat, karena mencari baris yang tidak
        | ada memang bukan kesalahan.
        |
        */

        $user = $this->users->findByTelegramId($message['from']['id'] ?? 0);

        if (str_starts_with($text, '/')) {
            $this->command($message, $text, $user);

            return;
        }

        if ($user === null) {
            return;
        }

        $state = $this->sessions->current((int) $user->id);

        /*
        |----------------------------------------------------------------------
        | Foto
        |----------------------------------------------------------------------
        |
        | Diperiksa SEBELUM pencocokan state berbasis teks. Pesan foto datang
        | tanpa `text` sama sekali, jadi kalau dibiarkan jatuh ke cabang
        | pencarian di bawah, bukti bayar yang dikirim pengguna akan
        | ditafsirkan sebagai kata kunci kosong — dan hilang tanpa jejak.
        |
        | Foto di luar state PAY_PROOF sengaja diabaikan diam-diam. Orang
        | mengirim gambar ke bot karena bermacam alasan, dan membalas setiap
        | satunya dengan "saya tidak mengerti" membuat bot terasa cerewet.
        |
        */

        if (isset($message['photo']) && $state === PremiumHandler::STATE_PROOF) {

            app(PaymentProofHandler::class)->handle(
                $message,
                $user,
                $this->sessions->payload((int) $user->id)
            );

            return;
        }

        match ($state) {

            'SEARCH' => app(SearchHandler::class)
                ->handle(
                    $chatId,
                    (int) $user->id,
                    $text
                ),

            PremiumHandler::STATE_PROOF => app(TelegramServiceInterface::class)
                ->sendMessage(
                    $chatId,
                    'Saya sedang menunggu <b>foto</b> bukti pembayaran. Kirim '
                    .'tangkapan layarnya, atau tekan /vip untuk membatalkan.'
                ),

            default => null,

        };
    }

    private function command(array $message, string $text, mixed $user): void
    {
        $chatId = $message['chat']['id'];
        $command = mb_strtolower((string) str($text)->before(' ')->before('@')->ltrim('/'));

        /*
        | Ejaannya diterima longgar, dan itu bukan kemurahan hati.
        |
        | "Afiliasi" dan "affiliate" hanya berbeda satu huruf di dua tempat,
        | dan orang yang mengetiknya cepat dari ponsel akan salah — terbukti
        | pada percobaan pertama fitur ini dipakai. Perintah yang benar
        | ditolak karena satu huruf membuat orang mengira fiturnya rusak,
        | lalu berhenti mencoba.
        |
        | Daftar ini menerima seluruh ejaan yang wajar dalam dua bahasa,
        | termasuk yang salah dengan cara yang bisa ditebak. Tidak ada
        | risikonya: tidak satu pun bentrok dengan perintah lain.
        */
        $ejaanAfiliasi = [
            'afiliasi', 'affiliasi', 'afiliate', 'affiliate',
            'afiliase', 'affiliase', 'referral', 'refferal',
        ];

        if (in_array($command, $ejaanAfiliasi, true)) {

            $bagian = preg_split('/\s+/', trim($text), 2);

            app(\App\Telegram\Handlers\AffiliateAdminHandler::class)
                ->handle($chatId, $user, $bagian[1] ?? '');

            return;
        }

        $action = match ($command) {
            'status', 'profil', 'profile' => TelegramMenuAction::PROFILE,
            'vip', 'premium' => TelegramMenuAction::PREMIUM,
            'search', 'cari' => TelegramMenuAction::SEARCH,
            'lanjut', 'continue' => TelegramMenuAction::CONTINUE,
            'favorit', 'favorite' => TelegramMenuAction::FAVORITE,
            'riwayat', 'history' => TelegramMenuAction::HISTORY,
            'terbaru', 'latest' => TelegramMenuAction::LATEST,
            'trending', 'populer' => TelegramMenuAction::TRENDING,
            'website', 'web' => TelegramMenuAction::WEBSITE,
            'help', 'bantuan' => TelegramMenuAction::HELP,
            default => null,
        };

        if ($action === null) {
            app(TelegramServiceInterface::class)->sendMessage(
                $chatId,
                'Command tidak dikenali. Tekan /start untuk membuka menu utama.'
            );

            return;
        }

        if ($user === null) {
            app(TelegramServiceInterface::class)->sendMessage(
                $chatId,
                'Kirim /start dulu supaya akun Anda dikenali.'
            );

            return;
        }

        if ($action->startsConversation()) {
            app(SearchHandler::class)->start((int) $chatId, (int) $user->id);

            return;
        }

        $handler = $action->handler();

        if ($handler === null) {
            app(StartHandler::class)->handle($message);

            return;
        }

        app($handler)->handle([
            'message' => $message,
            'from' => $message['from'] ?? [],
        ], $user);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Catat update yang tidak ditangani, tanpa isinya.
     *
     * Yang paling berguna: `channel_post`. Kirim satu pesan ke channel
     * penyimpanan, lalu baris ini akan memuat id channelnya —
     * satu-satunya cara membacanya tanpa alat bantu di luar sistem sendiri.
     */
    private function logUnhandled(array $update): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        $jenis = collect(array_keys($update))
            ->reject(fn ($k) => $k === 'update_id')
            ->first() ?? 'tidak dikenal';

        $chat = $update[$jenis]['chat'] ?? [];

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->info('telegram.update.unhandled', [
                'jenis'     => $jenis,
                'chat_id'   => $chat['id'] ?? null,
                'chat_type' => $chat['type'] ?? null,
                'chat_name' => $chat['title'] ?? ($chat['username'] ?? null),
            ]);
    }
}
