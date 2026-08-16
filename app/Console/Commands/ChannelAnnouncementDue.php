<?php

namespace App\Console\Commands;

use App\Jobs\SendChannelAnnouncement;
use App\Models\ChannelAnnouncement;
use Illuminate\Console\Command;

/**
 * Memungut pengumuman terjadwal yang waktunya sudah tiba.
 *
 * Dipanggil scheduler setiap menit (lihat `routes/console.php`).
 *
 * ## Kenapa lewat tabel, bukan job berjadwal
 *
 * Cara paling singkat adalah mengantrekan job dengan `delay()` sepanjang
 * jarak ke waktu tayang. Yang hilang di sana adalah kendali: job yang sudah
 * duduk di antrean tidak bisa dilihat isinya dari panel, tidak bisa diubah
 * jam tayangnya, dan ikut lenyap tanpa suara kalau antreannya dikosongkan.
 *
 * Dengan barisnya sebagai sumber kebenaran, pembatalan cukup mengubah satu
 * kolom, dan pengumuman yang terlewat karena worker sempat mati tetap
 * terkirim pada menit berikutnya — bukan hilang selamanya.
 */
class ChannelAnnouncementDue extends Command
{
    protected $signature = 'channel:announce-due {--dry-run : Tampilkan yang jatuh tempo tanpa mengirim.}';

    protected $description = 'Antrekan pengumuman channel yang jadwalnya sudah tiba';

    public function handle(): int
    {
        $jatuhTempo = ChannelAnnouncement::query()
            ->jatuhTempo()
            ->orderBy('scheduled_at')

            /*
            | Dibatasi, walau biasanya cuma ada satu dua.
            |
            | Batas ini menjaga keadaan yang tidak wajar: scheduler yang mati
            | seminggu lalu hidup lagi, dan seluruh pengumuman yang menumpuk
            | ditembakkan dalam satu menit. Sisanya menyusul menit berikutnya.
            */
            ->limit(20)
            ->get();

        if ($jatuhTempo->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($jatuhTempo as $pengumuman) {

            $waktu = $pengumuman->scheduled_at?->format('d M Y H:i') ?? '—';

            if ($this->option('dry-run')) {
                $this->line("#{$pengumuman->id} — dijadwalkan {$waktu}");

                continue;
            }

            SendChannelAnnouncement::dispatch($pengumuman->id);
        }

        if (! $this->option('dry-run')) {
            $this->info($jatuhTempo->count().' pengumuman diantrekan.');
        }

        return self::SUCCESS;
    }
}
