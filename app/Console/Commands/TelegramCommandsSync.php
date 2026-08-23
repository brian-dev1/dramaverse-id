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
            // Dulu bernama /status dan hanya memuat langganan. Isinya sekarang
            // jauh lebih luas — akun, tagihan, aktivitas, dan Program Affiliate —
            // sehingga namanya diubah jadi /profile agar sesuai isinya.
            // /status tetap dikenali router sebagai alias, supaya tautan dan
            // kebiasaan lama tidak mendadak berhenti bekerja.
            ['command' => 'profile', 'description' => 'Profil, VIP & referral'],
            // Daftar paketnya sendiri sudah pindah ke halaman VIP website;
            // perintah ini membuka halaman itu. Keterangannya diubah supaya
            // tidak menjanjikan daftar harga yang muncul di dalam chat.
            ['command' => 'vip', 'description' => 'Buka halaman harga VIP'],
            ['command' => 'search', 'description' => 'Cari drama'],
            ['command' => 'lanjut', 'description' => 'Lanjut menonton'],
            ['command' => 'favorit', 'description' => 'Daftar favorit'],
            ['command' => 'riwayat', 'description' => 'Riwayat tontonan'],
            ['command' => 'terbaru', 'description' => 'Part terbaru'],
            ['command' => 'trending', 'description' => 'Drama populer'],
            ['command' => 'website', 'description' => 'Buka aplikasi'],
            ['command' => 'help', 'description' => 'Bantuan'],
        ];
    }
}
