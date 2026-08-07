<?php

namespace App\Console\Commands;

use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Console\Command;
use App\Support\Waktu;

/**
 * Membuktikan lapisan Telegram benar-benar bekerja.
 *
 * Ini alat verifikasi terpenting sprint ini, dengan alasan yang sama seperti
 * `storage:test` di Sprint 7.1: seluruh alat pemeriksa proyek ini bersifat
 * statis — mereka membaca teks, tidak menjalankan PHP dan tidak menyentuh
 * jaringan. Konfigurasi Telegram bisa terlihat benar sampai baris terakhir
 * dan tetap ditolak server.
 *
 * Tanpa berkas ini, satu-satunya cara mengetahui token salah adalah menunggu
 * ada pengguna yang mengeluh pesannya tidak sampai.
 */
class TelegramTest extends Command
{
    protected $signature = 'telegram:test
                            {--chat= : Kirim pesan uji ke chat_id ini}
                            {--message= : Isi pesan uji}';

    protected $description = 'Uji token, koneksi, dan pengiriman pesan Telegram';

    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /*
        |----------------------------------------------------------------------
        | 1. Konfigurasi
        |----------------------------------------------------------------------
        */

        if (! $this->telegram->isConfigured()) {

            $this->components->error('TELEGRAM_BOT_TOKEN belum diisi.');

            $this->components->bulletList([
                'Ambil token dari @BotFather di Telegram.',
                'Isikan ke .env pada baris TELEGRAM_BOT_TOKEN.',
                'Jalankan: php artisan config:cache',
            ]);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('API', (string) config('telegram.api_url'));
        $this->components->twoColumnDetail('Batas waktu', config('telegram.timeout').' detik');
        $this->components->twoColumnDetail('Percobaan', (string) config('telegram.retry.times'));

        /*
        |----------------------------------------------------------------------
        | 2. getMe — token dan jaringan sekaligus
        |----------------------------------------------------------------------
        */

        $this->newLine();

        try {
            $bot = $this->telegram->getMe();
        } catch (TelegramException $e) {
            return $this->report($e, 'getMe');
        }

        $this->components->info('Terhubung.');

        $this->components->twoColumnDetail('Bot', '@'.$bot->get('username', '?'));
        $this->components->twoColumnDetail('Nama', (string) $bot->get('first_name', '?'));
        $this->components->twoColumnDetail('ID', (string) $bot->get('id', '?'));
        $this->components->twoColumnDetail('Waktu tanggap', $bot->durationMs.' ms');

        // Ketidakcocokan ini tidak membuat apa pun gagal sekarang, tapi
        // membuat tautan t.me yang dikirim ke pengguna menunjuk bot lain.
        $configured = $this->telegram->botUsername();

        if ($configured !== null && strcasecmp($configured, (string) $bot->get('username')) !== 0) {
            $this->components->warn(sprintf(
                'TELEGRAM_BOT_USERNAME berisi "%s", tapi token ini milik "@%s". '
                .'Tautan t.me yang dibuat aplikasi akan salah arah.',
                $configured,
                $bot->get('username')
            ));
        }

        /*
        |----------------------------------------------------------------------
        | 3. Webhook — sekadar keterangan
        |----------------------------------------------------------------------
        */

        $this->newLine();

        try {
            $webhook = $this->telegram->query('getWebhookInfo');

            $url = (string) $webhook->get('url');

            $this->components->twoColumnDetail(
                'Webhook',
                $url === '' ? 'belum didaftarkan' : $url
            );

            $this->components->twoColumnDetail(
                'Update tertahan',
                (string) $webhook->get('pending_update_count', 0)
            );

            if ($pesan = $webhook->get('last_error_message')) {
                $this->components->warn('Galat webhook terakhir: '.$pesan);
            }
        } catch (TelegramException $e) {
            $this->components->warn('getWebhookInfo gagal: '.$e->getMessage());
        }

        /*
        |----------------------------------------------------------------------
        | 4. Pengiriman sungguhan
        |----------------------------------------------------------------------
        |
        | getMe membuktikan token dan jaringan. Ia TIDAK membuktikan pesan
        | bisa sampai ke orang — untuk itu chat_id-nya harus disebut, dan
        | pengguna yang bersangkutan harus sudah pernah menekan Start.
        |
        */

        $chat = $this->option('chat');

        if (blank($chat)) {

            $this->newLine();

            $this->components->info(
                'Token dan koneksi terbukti. Untuk membuktikan pengiriman: '
                .'php artisan telegram:test --chat=<telegram_id>'
            );

            return self::SUCCESS;
        }

        $pesan = $this->option('message')
            ?: 'Uji koneksi DramaVerse ID pada '.Waktu::ringkas(now()).'.';

        $this->newLine();

        try {
            $hasil = $this->telegram->sendMessage($chat, $pesan);
        } catch (TelegramException $e) {
            return $this->report($e, 'sendMessage');
        }

        $this->components->info(sprintf(
            'Pesan terkirim ke %s (message_id %s, %d ms, percobaan ke-%d).',
            $chat,
            $hasil->messageId() ?? '?',
            $hasil->durationMs,
            $hasil->attempts
        ));

        return self::SUCCESS;
    }

    /**
     * Laporkan kegagalan apa adanya, termasuk pesan panjang dari Telegram.
     */
    private function report(TelegramException $e, string $method): int
    {
        $this->components->error("{$method} gagal.");

        $this->components->twoColumnDetail('Pesan', $e->getMessage());

        if ($e->errorCode !== null) {
            $this->components->twoColumnDetail('error_code', (string) $e->errorCode);
        }

        $this->components->twoColumnDetail('Percobaan', (string) $e->attempts);

        if ($hint = $e->hint()) {
            $this->newLine();

            $this->components->warn($hint);
        }

        return self::FAILURE;
    }
}
