<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memanaskan cache dashboard analitik.
 *
 * Dijalankan scheduler. Yang membuka dashboard tepat setelah cache
 * kedaluwarsa akan menunggu selama belasan query agregat berjalan;
 * memanaskannya di latar memindahkan tunggu itu ke tempat yang tidak ada
 * orangnya.
 *
 * Bisa juga dijalankan manual setelah impor data besar, ketika angka di layar
 * jelas tertinggal dan menunggu lima menit tidak enak dilakukan sambil
 * ditonton orang.
 */
class AnalyticsRefresh extends Command
{
    protected $signature = 'analytics:refresh';

    protected $description = 'Hitung ulang dan panaskan cache dashboard analitik';

    public function __construct(
        protected AnalyticsService $analytics
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mulai = microtime(true);

        try {
            $jumlah = $this->analytics->warm();

        } catch (Throwable $e) {

            // Perintah terjadwal yang mati diam-diam adalah perintah yang
            // dikira berjalan padahal tidak. Dicatat, dan keluar dengan kode
            // gagal supaya scheduler bisa melaporkannya.
            $this->components->error('Gagal: '.$e->getMessage());

            Log::error('analytics.refresh.failed', ['sebab' => $e->getMessage()]);

            return self::FAILURE;
        }

        $durasi = (int) round((microtime(true) - $mulai) * 1000);

        $this->components->info("{$jumlah} seksi dihitung ulang dalam {$durasi} ms.");

        Log::info('analytics.refresh.done', ['seksi' => $jumlah, 'duration_ms' => $durasi]);

        return self::SUCCESS;
    }
}
