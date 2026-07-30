<?php

namespace App\Services\Telegram;

use App\Models\Episode;
use App\Models\EpisodeVideo;
use Illuminate\Support\Facades\Cache;

/**
 * Cache untuk data yang dibaca setiap penekanan tombol tapi nyaris tidak
 * pernah berubah.
 *
 * Dua hal saja: `telegram_file_id` dan metadata episode. Keduanya dibaca pada
 * setiap permintaan menonton, setiap Next, setiap Previous — dan keduanya
 * hanya berubah saat admin menyinkronkan atau menyunting episode.
 *
 * ## Dibuang secara eksplisit, bukan menunggu kedaluwarsa
 *
 * TTL-nya satu jam, tetapi yang benar-benar menjaga kebenarannya adalah
 * `forget()` yang dipanggil saat datanya berubah. Menggantungkan diri pada
 * TTL berarti ada rentang sampai satu jam ketika bot mengirim `file_id` lama
 * untuk video yang baru diganti — dan gejalanya "video salah", bukan "video
 * gagal", yang jauh lebih sulit dikenali.
 */
class TelegramCacheService
{
    private const FILE = 'telegram:file:';

    private const EPISODE = 'telegram:episode:';

    /**
     * `file_id` untuk satu episode, atau null bila belum tersinkron.
     *
     * Null ikut disimpan. Tanpa itu, episode yang memang belum tersinkron
     * akan menembus cache pada setiap permintaan — dan justru episode itulah
     * yang paling sering diminta orang, karena tautannya tersebar sebelum
     * videonya siap.
     */
    public function fileId(int $episodeId): ?string
    {
        if (! $this->aktif()) {
            return $this->readFileId($episodeId);
        }

        $nilai = Cache::remember(
            self::FILE.$episodeId,
            $this->ttl(),
            fn () => $this->readFileId($episodeId) ?? ''
        );

        return $nilai === '' ? null : $nilai;
    }

    /** Metadata ringkas episode untuk caption dan tombol. */
    public function episode(int $episodeId): ?array
    {
        if (! $this->aktif()) {
            return $this->readEpisode($episodeId);
        }

        return Cache::remember(
            self::EPISODE.$episodeId,
            $this->ttl(),
            fn () => $this->readEpisode($episodeId)
        );
    }

    /**
     * Buang cache satu episode.
     *
     * Dipanggil dari EpisodeVideoObserver setiap kali barisnya berubah atau
     * dihapus — termasuk saat sinkronisasi berhasil, yang justru satu-satunya
     * saat `file_id` berubah dari kosong jadi terisi.
     */
    public function forget(int $episodeId): void
    {
        Cache::forget(self::FILE.$episodeId);

        Cache::forget(self::EPISODE.$episodeId);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembacaan sungguhan
    |--------------------------------------------------------------------------
    */

    private function readFileId(int $episodeId): ?string
    {
        $video = EpisodeVideo::query()
            ->where('episode_id', $episodeId)
            ->first();

        return $video?->isSyncedToTelegram() ? $video->telegram_file_id : null;
    }

    private function readEpisode(int $episodeId): ?array
    {
        $episode = Episode::with('drama:id,title')->find($episodeId);

        if ($episode === null) {
            return null;
        }

        return [
            'id'             => $episode->id,
            'drama_id'       => $episode->drama_id,
            'drama_title'    => $episode->drama?->title,
            'episode_number' => $episode->episode_number,
            'title'          => $episode->title,
        ];
    }

    private function aktif(): bool
    {
        return (bool) config('telegram.cache.enabled', true);
    }

    private function ttl(): int
    {
        return (int) config('telegram.cache.ttl', 3600);
    }
}
