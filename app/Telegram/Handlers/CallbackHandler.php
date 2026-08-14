<?php

namespace App\Telegram\Handlers;

use App\Enums\TelegramMenuAction;
use App\Models\User;
use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Telegram\Keyboards\EpisodeKeyboard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meneruskan penekanan tombol inline ke handler yang sesuai.
 *
 * ## Dua jenis callback
 *
 * 1. **Menu** — nilai `TelegramMenuAction` apa adanya (`search`, `help`).
 *    Susunannya diatur admin dari panel; pemetaan ke handler ada di enum-nya.
 * 2. **Berparameter** — `w:12`, `el:3:2`, `fv:3`, `up`. Awalannya dipisah
 *    titik dua, dan nilai enum tidak pernah memuat titik dua, jadi keduanya
 *    tidak bisa bentrok.
 *
 * ## Kenapa penggunanya dicari di sini
 *
 * Hampir semua handler butuh tahu siapa yang menekan tombol — untuk
 * membership, favorit, dan riwayat. Mencarinya di masing-masing handler
 * berarti sepuluh tempat yang harus ingat menerjemahkan `telegram_id` ke
 * `users.id`, dan yang lupa akan gagal dengan cara yang sudah pernah
 * terjadi: melanggar foreign key, atau diam-diam tidak menemukan apa pun.
 */
class CallbackHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected TelegramRepositoryInterface $repository
    ) {
    }

    public function handle(array $callback): void
    {
        // Hentikan animasi tunggu SEGERA, sebelum pekerjaan yang lama.
        // Telegram menyerah setelah beberapa detik dan tombolnya akan
        // terlihat menggantung.
        $this->acknowledge($callback);

        $data = (string) ($callback['data'] ?? '');

        $chatId = $callback['message']['chat']['id'] ?? null;

        if ($chatId === null) {
            return;
        }

        $user = $this->repository->findByTelegramId($callback['from']['id'] ?? 0);

        try {
            $this->route($callback, $data, $chatId, $user);
        } catch (TelegramException $e) {

            // Diteruskan, bukan ditangani di sini. Cabang ini ada justru
            // supaya `catch (Throwable)` di bawah tidak menelannya lebih
            // dulu: kegagalan Telegram sudah dicatat lengkap oleh client, dan
            // yang menahannya adalah TelegramWebhookController — di sanalah
            // keputusan "tetap jawab 200 supaya update tidak dikirim ulang"
            // dibuat, satu tempat untuk semua jalur.
            throw $e;

        } catch (Throwable $e) {

            Log::error('telegram.callback.error', [
                'data'    => $data,
                'user_id' => $user?->id,
                'sebab'   => $e->getMessage(),
                'kelas'   => $e::class,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Maaf, ada yang bermasalah saat memproses permintaan itu. '
                .'Coba lagi, atau tekan /start untuk kembali ke menu.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Penerusan
    |--------------------------------------------------------------------------
    */

    private function route(array $callback, string $data, int|string $chatId, ?User $user): void
    {
        [$awalan, $argumen] = $this->parse($data);

        switch ($awalan) {

            case EpisodeKeyboard::WATCH:
                app(WatchHandler::class)->handle($chatId, $user, (int) ($argumen[0] ?? 0));

                return;

            case EpisodeKeyboard::LIST:
                app(EpisodeListHandler::class)->handle(
                    $chatId,
                    (int) ($argumen[0] ?? 0),
                    (int) ($argumen[1] ?? 1)
                );

                return;

            case EpisodeKeyboard::FAVORITE:
                app(FavoriteHandler::class)->toggle($callback, $user, (int) ($argumen[0] ?? 0));

                return;

            case EpisodeKeyboard::UPGRADE:
                app(PremiumHandler::class)->handle($callback, $user);

                return;

            /*
            |------------------------------------------------------------------
            | Tombol wilayah pembayaran sudah tidak ada
            |------------------------------------------------------------------
            |
            | Pertanyaan "Anda membayar dari mana?" pindah ke halaman VIP di
            | website bersama daftar paketnya. Tombol `payreg:ID` yang masih
            | menempel di pesan-pesan lama sengaja tidak diberi cabang sendiri:
            | awalannya tidak dikenali, jatuh ke bagian menu di bawah, dan
            | berakhir di menu utama — jawaban yang benar untuk tombol usang.
            |
            */

            case PremiumHandler::BUY:
                app(PremiumHandler::class)->buy($callback, $user, (int) ($argumen[0] ?? 0));

                return;

            case PremiumHandler::PAID:
                app(PremiumHandler::class)->confirmPaid($callback, $user, (int) ($argumen[0] ?? 0));

                return;

            /*
            |------------------------------------------------------------------
            | "Saya sudah gabung"
            |------------------------------------------------------------------
            |
            | Hasil pemeriksaan yang tersimpan dibuang lebih dulu, lalu
            | diperiksa ulang. Tanpa pembuangan itu tombolnya tidak akan
            | pernah bekerja: jawabannya diambil dari cache yang barusan
            | menyatakan pengguna bukan anggota, dan orang yang benar-benar
            | baru bergabung akan menekannya berkali-kali tanpa hasil.
            |
            */

            case \App\Services\Telegram\ChannelGate::RECHECK:

                $gate = app(\App\Services\Telegram\ChannelGate::class);

                $gate->lupakan($user);

                if ($gate->lolos($user)) {

                    $this->telegram->sendMessage(
                        $chatId,
                        \App\Support\Telegram\Notice::make('✅', 'Terima kasih sudah bergabung')
                            ->lead('Sekarang Anda bisa menonton dan berlangganan seperti biasa.')
                            ->note('Tekan tombol tonton yang tadi, atau /start untuk membuka menu.')
                            ->render()
                    );

                    return;
                }

                [$pesan, $opsi] = $gate->penahan();

                $this->telegram->sendMessage(
                    $chatId,
                    'Belum terbaca sebagai anggota channel. Pastikan Anda menekan '
                    ."Join di channelnya, lalu coba lagi.\n\n".$pesan,
                    $opsi
                );

                return;
        }

        /*
        |----------------------------------------------------------------------
        | Menu
        |----------------------------------------------------------------------
        */

        $action = TelegramMenuAction::tryFrom($data);

        // Tombol yang tidak dikenal: tampilkan menu utama. Ini bukan sekadar
        // penjagaan — tombol lama tetap menempel di pesan yang sudah terkirim
        // setelah menunya diubah dari panel, dan yang menekannya harus
        // mendapat sesuatu.
        if ($action === null || $action->isLink()) {
            $this->home($callback);

            return;
        }

        if ($action->startsConversation()) {
            $this->startSearch($chatId, $user, $callback);

            return;
        }

        $handler = $action->handler();

        if ($handler === null) {
            $this->home($callback);

            return;
        }

        // Handler yang butuh tahu penggunanya menerimanya sebagai argumen
        // kedua. Yang tidak butuh mengabaikannya — tanda tangannya opsional,
        // jadi keduanya bisa dipanggil dengan cara yang sama.
        app($handler)->handle($callback, $user);
    }

    /**
     * Pecah `el:3:2` jadi ['el', ['3','2']].
     *
     * Data tanpa titik dua dikembalikan apa adanya sebagai awalan, sehingga
     * nilai menu tetap lewat jalur yang sama.
     */
    private function parse(string $data): array
    {
        $bagian = explode(':', $data);

        return [array_shift($bagian), $bagian];
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Konfirmasi ke Telegram bahwa tombolnya diterima.
     *
     * Kegagalannya ditelan dengan sengaja: `callback_query_id` kedaluwarsa
     * setelah beberapa detik, dan tombol yang ditekan pada pesan lama akan
     * selalu ditolak Telegram di sini. Membiarkannya melempar akan
     * membatalkan seluruh permintaan hanya karena konfirmasi kosmetik gagal.
     */
    private function acknowledge(array $callback): void
    {
        try {
            $this->telegram->answerCallbackQuery($callback['id'] ?? '');
        } catch (TelegramException) {
            // Sudah tercatat di log oleh client.
        }
    }

    /**
     * State percakapan disimpan dengan id pengguna di basis data kita, BUKAN
     * telegram_id — `user_sessions.user_id` adalah foreign key ke `users.id`.
     */
    private function startSearch(int|string $chatId, ?User $user, array $callback): void
    {
        if ($user === null) {
            $this->home($callback);

            return;
        }

        app(SearchHandler::class)->start((int) $chatId, (int) $user->id);
    }

    private function home(array $callback): void
    {
        app(StartHandler::class)->handle([
            'chat' => $callback['message']['chat'],
            'from' => $callback['from'],
            'text' => '/start',
        ]);
    }
}
