<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\StorageProviderService;
use Illuminate\Console\Command;

/**
 * Menguji koneksi ke storage provider dari baris perintah.
 *
 * Ini alat verifikasi paling penting untuk sprint ini. Seluruh lapisan
 * storage tidak bisa diperiksa secara statis: konfigurasi bisa terlihat benar
 * sampai baris terakhir dan tetap ditolak server penyimpanan. Jalankan
 * perintah ini setelah menambah provider, dan setelah setiap deploy.
 */
class StorageTest extends Command
{
    protected $signature = 'storage:test
                            {slug? : Slug provider tertentu. Kosongkan untuk menguji semua}
                            {--all : Uji juga provider yang berstatus nonaktif}';

    protected $description = 'Uji koneksi tulis, baca, dan hapus ke storage provider';

    public function __construct(
        protected StorageProviderService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $providers = $this->resolveProviders();

        if ($providers->isEmpty()) {
            $this->components->warn(
                'Tidak ada provider yang bisa diuji. Jalankan '
                .'`php artisan db:seed --class=Database\\Seeders\\StorageProviderSeeder` '
                .'atau tambahkan provider dari panel admin.'
            );

            return self::FAILURE;
        }

        $rows = [];

        $failures = 0;

        foreach ($providers as $provider) {

            // Alasan tidak bisa diuji dilaporkan, bukan dilewati diam-diam.
            if ($skip = $this->skipReason($provider)) {
                $rows[] = [$provider->slug, $provider->driver->label(), 'DILEWATI', '-', $skip];

                continue;
            }

            $this->components->task(
                "Menguji {$provider->slug}",
                function () use ($provider, &$rows, &$failures) {

                    $result = $this->service->test($provider);

                    $rows[] = [
                        $provider->slug,
                        $provider->driver->label(),
                        $result->success ? 'OK' : 'GAGAL',
                        $result->durationForHumans() ?? '-',
                        $result->message,
                    ];

                    if ($result->failed()) {
                        $failures++;
                    }

                    return $result->success;
                }
            );
        }

        $this->newLine();

        $this->table(['Slug', 'Driver', 'Hasil', 'Durasi', 'Pesan'], $rows);

        if ($failures > 0) {
            $this->components->error("{$failures} provider gagal diuji.");

            return self::FAILURE;
        }

        $this->components->info('Semua provider yang diuji berhasil.');

        return self::SUCCESS;
    }

    protected function resolveProviders()
    {
        $slug = $this->argument('slug');

        if ($slug) {
            $provider = StorageProvider::where('slug', $slug)->first();

            if ($provider === null) {
                $this->components->error("Provider `{$slug}` tidak ditemukan.");

                return collect();
            }

            return collect([$provider]);
        }

        $providers = $this->service->all();

        return $this->option('all')
            ? $providers
            : $providers->filter->isActive()->values();
    }

    /**
     * Alasan sebuah provider tidak bisa diuji, atau null kalau bisa.
     */
    protected function skipReason(StorageProvider $provider): ?string
    {
        if (! $provider->isConfigured()) {
            return 'Field kosong: '.implode(', ', $provider->missingFields());
        }

        if (! $provider->hasAdapterInstalled()) {
            return 'Perlu: composer require '.$provider->driver->composerPackage();
        }

        return null;
    }
}
