<?php

namespace App\Console\Commands;

use App\Models\Episode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Terbitkan part yang videonya sudah siap tetapi masih berstatus draf.
 *
 * ## Kenapa perintah ini ada
 *
 * `EpisodeVideoObserver` menerbitkan part secara otomatis begitu videonya
 * tersinkron ke Telegram — tetapi hanya untuk yang tersinkron SETELAH
 * observer itu dipasang. Part yang videonya sudah lebih dulu siap tidak
 * pernah terpicu, dan akan menunggu selamanya.
 *
 * Perintah ini menutup celah itu sekali jalan.
 *
 * ## Syaratnya
 *
 * Sebuah part diterbitkan hanya bila SEMUA ini benar:
 *
 * - statusnya masih draf;
 * - punya baris video dengan `sync_status = synced` dan `telegram_file_id`
 *   terisi — inilah satu-satunya bukti bahwa penonton benar-benar bisa
 *   menontonnya;
 * - `published_at`-nya tidak berada di masa depan. Tanggal di masa depan
 *   berarti jadwal tayang yang sengaja dipasang, dan itu urusan
 *   `EpisodeSchedulerService`, bukan perintah ini.
 *
 * ```
 * php artisan episode:terbitkan-siap                # laporan saja
 * php artisan episode:terbitkan-siap --terapkan     # benar-benar terbitkan
 * php artisan episode:terbitkan-siap --drama=12
 * ```
 */
class EpisodeTerbitkanSiap extends Command
{
    protected $signature = 'episode:terbitkan-siap
                            {--drama= : Batasi ke satu drama (id)}
                            {--terapkan : Benar-benar terbitkan}
                            {--jumlah=40 : Berapa baris laporan yang ditampilkan}';

    protected $description = 'Terbitkan part yang videonya sudah tersinkron ke Telegram tapi masih draf';

    public function handle(): int
    {
        $terapkan = (bool) $this->option('terapkan');
        $tampil = max(1, (int) $this->option('jumlah'));

        $query = Episode::query()
            ->with(['drama:id,title'])
            ->where('status', '!=', 'published')
            ->whereHas('video', function ($q): void {
                $q->where('sync_status', 'synced')
                    ->whereNotNull('telegram_file_id');
            })
            ->where(function ($q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });

        if ($this->option('drama') !== null) {
            $query->where('drama_id', $this->option('drama'));
        }

        $siap = $query->orderBy('drama_id')
            ->orderBy('episode_number')
            ->get(['id', 'drama_id', 'episode_number', 'title', 'status', 'published_at']);

        if ($siap->isEmpty()) {
            $this->info('Tidak ada part draf yang videonya sudah siap.');

            $this->jelaskanYangTertinggal();

            return self::SUCCESS;
        }

        $this->line('');
        $this->table(
            ['Drama', 'Part', 'Judul', 'Status'],
            $siap->take($tampil)->map(fn (Episode $e): array => [
                Str::limit($e->drama?->title ?? '-', 38),
                $e->episode_number,
                Str::limit($e->title ?: '-', 24),
                $e->status,
            ])->all()
        );

        if ($siap->count() > $tampil) {
            $this->line('  ... dan '.($siap->count() - $tampil).' part lainnya.');
        }

        $this->line('');
        $this->line($siap->count().' part siap diterbitkan.');

        if (! $terapkan) {
            $this->line('');
            $this->info('Ini baru laporan — belum ada yang diterbitkan.');
            $this->line('Tambahkan --terapkan kalau daftarnya sudah sesuai.');

            return self::SUCCESS;
        }

        $diterbitkan = 0;

        DB::transaction(function () use ($siap, &$diterbitkan): void {
            foreach ($siap as $episode) {
                // save(), bukan update() massal, supaya EpisodeObserver
                // tetap berjalan dan aturan is_vip ikut terjaga.
                $episode->forceFill([
                    'status'       => 'published',
                    'published_at' => $episode->published_at ?? now(),
                ])->save();

                $diterbitkan++;
            }
        });

        $this->line('');
        $this->info("{$diterbitkan} part diterbitkan.");

        $this->jelaskanYangTertinggal();

        return self::SUCCESS;
    }

    /**
     * Part draf yang TIDAK ikut terbit, berikut alasannya.
     *
     * Tanpa keterangan ini, part yang tertinggal terlihat seperti perintah
     * yang gagal — padahal justru sedang menunggu sesuatu yang memang belum
     * selesai.
     */
    private function jelaskanYangTertinggal(): void
    {
        $tanpaVideo = Episode::where('status', '!=', 'published')
            ->whereDoesntHave('video')
            ->count();

        $belumSinkron = Episode::where('status', '!=', 'published')
            ->whereHas('video')
            ->whereDoesntHave('video', function ($q): void {
                $q->where('sync_status', 'synced')
                    ->whereNotNull('telegram_file_id');
            })
            ->count();

        if ($tanpaVideo === 0 && $belumSinkron === 0) {
            return;
        }

        $this->line('');
        $this->line('Masih draf karena belum siap:');

        if ($tanpaVideo > 0) {
            $this->line("  {$tanpaVideo} part belum punya video sama sekali.");
        }

        if ($belumSinkron > 0) {
            $this->line(
                "  {$belumSinkron} part sudah punya video tapi belum "
                .'tersinkron ke Telegram — jalankan sinkronisasi di '
                .'/admin/telegram/sync, lalu ulangi perintah ini.'
            );
        }
    }
}
