<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Monitoring\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Membuat, memeriksa, dan memangkas cadangan.
 *
 *   php artisan backup:run            buat, verifikasi, lalu pangkas
 *   php artisan backup:run --verify   hanya verifikasi yang terbaru
 *   php artisan backup:run --prune    hanya pangkas yang lama
 *   php artisan backup:run --list     daftar cadangan yang ada
 *
 * Ketiganya di satu perintah karena selalu dijalankan berurutan: cadangan
 * yang tidak diverifikasi tidak bisa dipercaya, dan cadangan yang tidak
 * dipangkas akan memenuhi disk sampai aplikasinya sendiri berhenti bekerja.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
                            {--verify : Hanya verifikasi cadangan terbaru}
                            {--prune : Hanya pangkas cadangan lama}
                            {--list : Tampilkan daftar cadangan}
                            {--keep= : Jumlah cadangan yang disimpan saat memangkas}';

    protected $description = 'Cadangkan basis data dan konfigurasi, verifikasi, lalu pangkas';

    public function __construct(
        protected BackupService $backup,
        protected AlertService $alerts
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->daftar();
        }

        if ($this->option('verify')) {
            return $this->verifikasiTerbaru();
        }

        if ($this->option('prune')) {
            return $this->pangkas();
        }

        return $this->penuh();
    }

    /*
    |--------------------------------------------------------------------------
    | Alur penuh
    |--------------------------------------------------------------------------
    */

    private function penuh(): int
    {
        $this->components->info('Membuat cadangan…');

        try {
            $path = $this->backup->create();

        } catch (Throwable $e) {

            $this->components->error($e->getMessage());

            // Cadangan yang gagal diam-diam adalah cadangan yang dikira ada.
            // Ini kritis: penahan dilewati.
            $this->alerts->critical(
                'backup-failed',
                'Cadangan gagal dibuat',
                $e->getMessage(),
                ['perintah' => 'backup:run']
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Berkas', basename($path));
        $this->components->twoColumnDetail('Ukuran', $this->ukuran((int) filesize($path)));

        if (config('backup.verify_after_create', true)) {

            $hasil = $this->backup->verify($path);

            $this->components->twoColumnDetail(
                'Verifikasi',
                $hasil['ok'] ? 'OK' : 'GAGAL'
            );

            $this->line('  '.$hasil['pesan']);

            if (! $hasil['ok']) {

                $this->alerts->critical(
                    'backup-corrupt',
                    'Cadangan baru tidak lolos verifikasi',
                    basename($path).': '.$hasil['pesan']
                        ."\n\nBerkasnya ada, tetapi tidak bisa dipercaya untuk restore.",
                    ['berkas' => basename($path)]
                );

                return self::FAILURE;
            }
        }

        $dihapus = $this->backup->prune($this->keep());

        $this->components->twoColumnDetail('Dipangkas', $dihapus.' cadangan lama');

        Log::info('backup.created', [
            'berkas'   => basename($path),
            'size'     => filesize($path),
            'dipangkas' => $dihapus,
        ]);

        $this->peringatanRuang();

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Sub-perintah
    |--------------------------------------------------------------------------
    */

    private function verifikasiTerbaru(): int
    {
        $terbaru = $this->backup->latest();

        if ($terbaru === null) {
            $this->components->warn('Belum ada cadangan sama sekali.');

            return self::FAILURE;
        }

        $hasil = $this->backup->verify($terbaru['path']);

        $this->components->twoColumnDetail($terbaru['nama'], $hasil['ok'] ? 'OK' : 'GAGAL');

        $this->line('  '.$hasil['pesan']);

        return $hasil['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function pangkas(): int
    {
        $dihapus = $this->backup->prune($this->keep());

        $this->components->info("{$dihapus} cadangan lama dihapus.");

        return self::SUCCESS;
    }

    private function daftar(): int
    {
        $semua = $this->backup->all();

        if ($semua->isEmpty()) {
            $this->components->warn('Belum ada cadangan.');

            return self::SUCCESS;
        }

        $this->table(
            ['Berkas', 'Ukuran', 'Dibuat', 'Umur'],
            $semua->map(fn (array $b) => [
                $b['nama'],
                $this->ukuran($b['size']),
                $b['waktu']->format('d M Y H:i'),
                $b['waktu']->diffForHumans(),
            ])->all()
        );

        $this->components->twoColumnDetail(
            'Total ruang terpakai',
            $this->ukuran($this->backup->totalSize())
        );

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Peringatkan bila disk hampir penuh.
     *
     * Cadangan berhenti berjalan saat disk penuh, dan kegagalannya terjadi
     * justru pada saat server paling perlu dicadangkan.
     */
    private function peringatanRuang(): void
    {
        $bebas = @disk_free_space(storage_path());

        $total = @disk_total_space(storage_path());

        if ($bebas === false || $total === false || $total <= 0) {
            return;
        }

        $persen = ($bebas / $total) * 100;

        if ($persen < 10) {
            $this->alerts->send(
                'disk-low',
                'Ruang disk menipis',
                sprintf(
                    'Sisa ruang %s dari %s (%.1f%%). Cadangan akan berhenti berjalan '
                    ."saat disk penuh.\n\nJalankan: php artisan backup:run --prune "
                    .'dan php artisan upload:prune',
                    $this->ukuran((int) $bebas),
                    $this->ukuran((int) $total),
                    $persen
                ),
                ['bebas' => (int) $bebas, 'total' => (int) $total]
            );
        }
    }

    private function keep(): ?int
    {
        $nilai = $this->option('keep');

        return is_numeric($nilai) ? (int) $nilai : null;
    }

    private function ukuran(int $byte): string
    {
        if ($byte >= 1073741824) {
            return number_format($byte / 1073741824, 2).' GB';
        }

        if ($byte >= 1048576) {
            return number_format($byte / 1048576, 1).' MB';
        }

        return number_format($byte / 1024, 0).' KB';
    }
}
