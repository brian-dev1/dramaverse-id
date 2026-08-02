<?php

namespace App\Console\Commands;

use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Console\Command;

class TelegramCommandsSync extends Command
{
    protected $signature = 'telegram:commands {--clear : Hapus daftar command bot dari Telegram}';

    protected $description = 'Pasang daftar slash command bot Telegram.';

    public function handle(TelegramServiceInterface $telegram): int
    {
        $commands = $this->option('clear') ? [] : $this->commands();

        $telegram->withTimeout(15)->withRetries(2)->call('setMyCommands', [
            'commands' => $commands,
        ]);

        if ($commands === []) {
            $this->components->info('Daftar command Telegram dihapus.');

            return self::SUCCESS;
        }

        $this->components->info('Daftar command Telegram berhasil dipasang.');

        foreach ($commands as $command) {
            $this->components->twoColumnDetail('/'.$command['command'], $command['description']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{command:string,description:string}>
     */
    private function commands(): array
    {
        return [
            ['command' => 'start', 'description' => 'Buka menu utama'],
            ['command' => 'status', 'description' => 'Cek status akun dan VIP'],
            ['command' => 'vip', 'description' => 'Lihat paket VIP'],
            ['command' => 'search', 'description' => 'Cari drama'],
            ['command' => 'lanjut', 'description' => 'Lanjut menonton'],
            ['command' => 'favorit', 'description' => 'Daftar favorit'],
            ['command' => 'riwayat', 'description' => 'Riwayat tontonan'],
            ['command' => 'terbaru', 'description' => 'Episode terbaru'],
            ['command' => 'trending', 'description' => 'Drama populer'],
            ['command' => 'website', 'description' => 'Buka website'],
            ['command' => 'help', 'description' => 'Bantuan'],
        ];
    }
}
