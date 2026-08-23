<?php

namespace App\Console\Commands;

use App\Models\Drama;
use App\Services\Admin\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Membuatkan turunan kecil untuk poster yang sudah terlanjur diunggah.
 *
 * Poster yang diunggah SETELAH turunan ini ada sudah dibuatkan otomatis oleh
 * MediaService. Perintah ini hanya untuk menyusul yang lama — sekali jalan,
 * lalu tidak perlu lagi kecuali posternya diganti di luar panel admin.
 */
class PosterDerive extends Command
{
    protected $signature = 'poster:derive
                            {--force : Buat ulang walau turunannya sudah ada}
                            {--chunk=200 : Jumlah baris yang dibaca sekaligus}';

    protected $description = 'Membuat turunan poster 360px WebP untuk kartu di ponsel.';

    public function handle(ImageProcessor $processor): int
    {
        if (! $processor->available() || ! function_exists('imagewebp')) {
            $this->components->error(
                'GD dengan dukungan WebP tidak tersedia di PHP ini. '
                .'Pasang ekstensi gd beserta dukungan webp, lalu ulangi.'
            );

            return self::FAILURE;
        }

        $disk = Storage::disk('public');

        $dibuat = 0;
        $dilewati = 0;
        $gagal = 0;

        $total = Drama::query()->whereNotNull('poster')->count();

        if ($total === 0) {
            $this->components->info('Tidak ada drama berposter.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Drama::query()
            ->whereNotNull('poster')
            ->select('id', 'poster')
            ->chunkById((int) $this->option('chunk'), function ($dramas) use (
                $processor, $disk, &$dibuat, &$dilewati, &$gagal, $bar
            ) {
                foreach ($dramas as $drama) {
                    $bar->advance();

                    $asli = $drama->poster;

                    if (! $disk->exists($asli)) {
                        $gagal++;

                        continue;
                    }

                    $turunan = ImageProcessor::derivativePath($asli);

                    if (! $this->option('force') && $disk->exists($turunan)) {
                        $dilewati++;

                        continue;
                    }

                    $hasil = $processor->derivative($disk->path($asli));

                    $hasil ? $dibuat++ : $gagal++;
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Turunan dibuat', (string) $dibuat);
        $this->components->twoColumnDetail('Sudah ada, dilewati', (string) $dilewati);
        $this->components->twoColumnDetail('Gagal / berkas hilang', (string) $gagal);

        return self::SUCCESS;
    }
}
