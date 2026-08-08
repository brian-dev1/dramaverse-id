<?php

namespace App\Observers;

use App\Models\Drama;
use App\Repositories\HomeRepository;

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
