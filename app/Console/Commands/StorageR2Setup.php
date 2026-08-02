<?php

namespace App\Console\Commands;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use App\Models\StorageProvider;
use App\Services\StorageProviderService;
use Illuminate\Console\Command;

class StorageR2Setup extends Command
{
    protected $signature = 'storage:r2:setup
                            {--activate : Aktifkan provider setelah disimpan}
                            {--default : Jadikan provider R2 sebagai default setelah aktif}
                            {--test : Jalankan Test Connection setelah disimpan}';

    protected $description = 'Buat atau perbarui storage provider Cloudflare R2 dari .env';

    public function __construct(
        protected StorageProviderService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $data = $this->payload();

        if ($missing = $this->missingEnv()) {
            $this->components->error('Konfigurasi R2 belum lengkap di .env: '.implode(', ', $missing));
            $this->line('Isi R2_BUCKET, R2_ACCOUNT_ID atau R2_ENDPOINT, R2_ACCESS_KEY_ID, dan R2_SECRET_ACCESS_KEY.');

            return self::FAILURE;
        }

        $provider = StorageProvider::where('slug', $data['slug'])->first();

        $provider = $provider === null
            ? $this->service->store($data + ['status' => StorageStatus::INACTIVE->value])
            : $this->service->update($provider, $data);

        $this->components->info(sprintf(
            'Provider R2 `%s` %s.',
            $provider->slug,
            $provider->wasRecentlyCreated ? 'dibuat' : 'diperbarui'
        ));

        if ($this->option('test') && ! $this->test($provider)) {
            return self::FAILURE;
        }

        if ($this->option('activate')) {
            try {
                $provider = $this->service->enable($provider->refresh());
                $this->components->info('Provider R2 diaktifkan.');
            } catch (\Throwable $e) {
                $this->components->error('Provider R2 gagal diaktifkan: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('default')) {
            try {
                $provider = $this->service->makeDefault($provider->refresh());
                $this->components->info('Provider R2 dijadikan default.');
            } catch (\Throwable $e) {
                $this->components->error('Provider R2 gagal dijadikan default: '.$e->getMessage());
                $this->line('Pastikan provider sudah aktif, lengkap, dan Test Connection berhasil.');

                return self::FAILURE;
            }
        }

        $this->components->info('Selesai. Unggahan video baru akan memakai R2 bila provider ini menjadi default.');

        return self::SUCCESS;
    }

    private function payload(): array
    {
        $config = config('storage.r2', []);

        return [
            'name'           => $config['provider_name'] ?? 'Cloudflare R2 Produksi',
            'slug'           => $config['provider_slug'] ?? 'r2',
            'driver'         => StorageDriver::R2->value,
            'bucket'         => $config['bucket'] ?? null,
            'endpoint'       => $this->endpoint(),
            'region'         => $config['region'] ?? 'auto',
            'access_key'     => $config['access_key'] ?? null,
            'secret_key'     => $config['secret_key'] ?? null,
            'root'           => ($config['root'] ?? null) ?: null,
            'public_url'     => ($config['public_url'] ?? null) ?: null,
            'visibility'     => $config['visibility'] ?? config('storage.default_visibility', 'private'),
            'use_path_style' => true,
            'priority'       => (int) ($config['priority'] ?? 10),
            'options'        => [
                'request_checksum_calculation'  => 'when_required',
                'response_checksum_validation'  => 'when_required',
            ],
        ];
    }

    private function endpoint(): ?string
    {
        $config = config('storage.r2', []);

        $endpoint = trim((string) ($config['endpoint'] ?? ''));

        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }

        $accountId = trim((string) ($config['account_id'] ?? ''));

        return $accountId === ''
            ? null
            : "https://{$accountId}.r2.cloudflarestorage.com";
    }

    private function missingEnv(): array
    {
        $config = config('storage.r2', []);

        $required = [
            'R2_BUCKET'            => $config['bucket'] ?? null,
            'R2_ACCESS_KEY_ID'     => $config['access_key'] ?? null,
            'R2_SECRET_ACCESS_KEY' => $config['secret_key'] ?? null,
        ];

        if (blank($config['endpoint'] ?? null) && blank($config['account_id'] ?? null)) {
            $required['R2_ACCOUNT_ID/R2_ENDPOINT'] = null;
        }

        return array_keys(array_filter($required, fn ($value) => blank($value)));
    }

    private function test(StorageProvider $provider): bool
    {
        $result = $this->service->test($provider->refresh());

        if ($result->success) {
            $this->components->info('Test Connection R2 berhasil: '.$result->message);

            return true;
        }

        $this->components->error('Test Connection R2 gagal: '.$result->message);

        if ($hint = $result->hint()) {
            $this->line($hint);
        }

        return false;
    }
}
