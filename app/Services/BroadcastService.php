<?php

namespace App\Services;

use App\Jobs\BroadcastEpisodeTelegramJob;
use App\Models\Episode;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\TelegramResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Pengumuman episode baru lewat Telegram.
 *
 * Dua method dengan peran berbeda:
 *
 * - `episode()` menaruh pekerjaan di antrean. Ini yang dipanggil dari alur
 *   web, supaya permintaan tidak menunggu Telegram.
 * - `sendEpisode()` benar-benar mengirim. Dipanggil job, atau langsung saat
 *   diuji dari baris perintah.
 *
 * Penyusunan kalimatnya ada di sini, bukan di dalam job: job adalah
 * pembungkus antrean, dan menaruh teks yang dilihat pengguna di dalamnya
 * membuat teks itu hanya bisa diubah oleh orang yang tahu ada job-nya.
 */
class BroadcastService
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /** Antrekan pengumuman satu episode ke satu chat. */
    public function episode(Episode $episode, string $chatId): void
    {
        BroadcastEpisodeTelegramJob::dispatch($episode, $chatId);
    }

    /**
     * Kirim pengumuman episode sekarang juga.
     *
     * @throws \App\Services\Telegram\Exceptions\TelegramException
     */
    public function sendEpisode(Episode $episode, int|string $chatId): TelegramResponse
    {
        $caption = $this->episodeCaption($episode);

        $gambar = $this->thumbnailUrl($episode);

        // Tanpa thumbnail, kirim teks saja. Memaksa sendPhoto dengan gambar
        // kosong hanya menghasilkan penolakan dari Telegram, dan akibatnya
        // pengumumannya tidak sampai sama sekali — padahal isi yang penting
        // ada di teksnya.
        if ($gambar === null) {
            return $this->telegram->sendMessage($chatId, $caption);
        }

        return $this->telegram->sendPhoto($chatId, $gambar, $caption);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    protected function episodeCaption(Episode $episode): string
    {
        return sprintf(
            "<b>%s</b>\nEpisode %s sudah tersedia.",
            e($episode->drama?->title ?? 'Drama'),
            e((string) $episode->episode_number)
        );
    }

    /**
     * URL thumbnail yang bisa diunduh Telegram, atau null bila tidak ada.
     *
     * Kolom `episodes.thumbnail` berisi path relatif di disk `public`, bukan
     * URL. Sebelum Sprint 8.1 nilai kolom itu dikirim apa adanya sebagai
     * parameter `photo`, dan Telegram membacanya sebagai file_id yang tidak
     * dikenal lalu menolak seluruh pengiriman. Karena tidak ada pemanggil
     * yang memeriksa nilai baliknya, kegagalan itu tidak pernah terlihat.
     *
     * Telegram mengunduh gambarnya sendiri dari internet, jadi URL ini harus
     * bisa dijangkau dari luar. Di localhost pengiriman memang akan gagal —
     * itu keadaan yang benar untuk dilaporkan, bukan disembunyikan.
     */
    protected function thumbnailUrl(Episode $episode): ?string
    {
        $path = trim((string) $episode->thumbnail);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
