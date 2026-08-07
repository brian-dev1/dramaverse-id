<?php

namespace App\Console\Commands;

use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Console\Command;

/**
 * Memasang tombol menu bot supaya membuka website sebagai Mini App.
 */
class TelegramMiniApp extends Command
{
    protected $signature = 'telegram:miniapp
                            {--url= : Alamat HTTPS mini app (default: config telegram.miniapp_url / APP_URL)}
                            {--text= : Label tombol menu}
                            {--reset : Kembalikan tombol menu ke daftar command bawaan}';

    protected $description = 'Pasang tombol menu Telegram Mini App untuk website.';

    public function handle(TelegramServiceInterface $telegram): int
    {
        if ($this->option('reset')) {
            $telegram->call('setChatMenuButton', [
                'menu_button' => json_encode(['type' => 'commands']),
            ]);

            $this->components->info('Tombol menu dikembalikan ke daftar command.');

            return self::SUCCESS;
        }

        $url = (string) ($this->option('url')
            ?: config('telegram.miniapp_url')
            ?: config('app.url'));

        $url = rtrim($url, '/').'/';

        if (! str_starts_with($url, 'https://')) {
            $this->components->error('Mini App wajib memakai HTTPS. URL sekarang: '.$url);

            return self::FAILURE;
        }

        $text = (string) ($this->option('text') ?: config('telegram.miniapp_button_text', 'Buka Website'));

        $telegram->withTimeout(15)->withRetries(2)->call('setChatMenuButton', [
            'menu_button' => json_encode([
                'type'    => 'web_app',
                'text'    => $text,
                'web_app' => ['url' => $url],
            ]),
        ]);

        $this->components->info('Tombol Mini App terpasang.');
        $this->components->twoColumnDetail('Label', $text);
        $this->components->twoColumnDetail('URL', $url);

        return self::SUCCESS;
    }
}
