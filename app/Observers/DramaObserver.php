<?php

namespace App\Observers;

use App\Jobs\PostDramaToChannel;
use App\Models\Drama;
use App\Repositories\HomeRepository;
use App\Services\Telegram\ChannelPostService;

/**
 * Menjaga cache katalog beranda tetap sinkron dengan tabel dramas.
 *
 * Observer, bukan panggilan di dalam controller, karena baris drama berubah
 * lewat beberapa jalur: form admin, aksi massal (`publish`/`draft`/`vip`),
 * seeder, dan perintah artisan. Menaruh pembuangan cache di salah satunya
 * berarti jalur lain diam-diam menyisakan cache basi.
 */
class DramaObserver
{
    public function saved(Drama $drama): void
    {
        HomeRepository::flushCatalog();

        $this->kirimKeChannelBilaBaruTerbit($drama);
    }

    /**
     * Antrekan postingan channel saat drama BERALIH menjadi terbit.
     *
     * ## Syaratnya sengaja ketat
     *
     * Yang memicu bukan "drama ini terbit", melainkan "drama ini baru saja
     * berubah dari belum terbit menjadi terbit". Bedanya menentukan: observer
     * `saved` berjalan di setiap penyimpanan, dan admin menyimpan drama yang
     * sama berkali-kali untuk membetulkan sinopsis, mengganti poster, atau
     * menambah genre. Memakai syarat yang pertama berarti satu postingan baru
     * di channel setiap kali salah ketik diperbaiki.
     *
     * `wasChanged('published_at')` menjawab pertanyaan yang benar: kolom itu
     * ikut berubah pada penyimpanan ini, atau tidak.
     *
     * Penjagaan terakhir tetap ada di dalam job — lihat `PostDramaToChannel`.
     */
    private function kirimKeChannelBilaBaruTerbit(Drama $drama): void
    {
        if (! $drama->wasChanged('published_at')) {
            return;
        }

        if ($drama->published_at === null || $drama->published_at->isFuture()) {
            return;
        }

        if (! app(ChannelPostService::class)->autoPostAktif()) {
            return;
        }

        PostDramaToChannel::dispatch($drama->id);
    }

    public function deleted(Drama $drama): void
    {
        HomeRepository::flushCatalog();
    }

    public function restored(Drama $drama): void
    {
        HomeRepository::flushCatalog();
    }
}
