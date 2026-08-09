<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Menghapus metadata yang tidak dipakai lagi:
|
|   dramas.release_year   — tahun rilis
|   dramas.duration       — durasi per episode (menit)
|   dramas.rating         — nilai rating katalog
|   dramas.trending_score — skor urut trending (flag is_trending tetap ada)
|   episodes.duration     — durasi video per episode (detik)
|
| Index gabungan yang memuat kolom-kolom itu ikut dibuang lebih dulu, karena
| MySQL menolak DROP COLUMN selama kolomnya masih menjadi bagian index.
|
| down() sengaja hanya mengembalikan struktur kolom, bukan isinya. Nilai lama
| hilang permanen begitu migrasi ini jalan.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dramas')) {
            $this->dropIndexIfExists('dramas', 'dramas_release_year_index');
            $this->dropIndexIfExists('dramas', 'dramas_rating_views_index');
            $this->dropIndexIfExists('dramas', 'dramas_is_trending_trending_score_index');

            $this->dropColumnsIfExist('dramas', [
                'release_year',
                'duration',
                'rating',
                'trending_score',
            ]);
        }

        if (Schema::hasTable('episodes')) {
            $this->dropIndexIfExists('episodes', 'episodes_duration_index');
            $this->dropColumnsIfExist('episodes', ['duration']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dramas')) {
            Schema::table('dramas', function (Blueprint $table) {
                if (! Schema::hasColumn('dramas', 'release_year')) {
                    $table->year('release_year')->nullable();
                }

                if (! Schema::hasColumn('dramas', 'duration')) {
                    $table->unsignedSmallInteger('duration')->nullable();
                }

                if (! Schema::hasColumn('dramas', 'rating')) {
                    $table->decimal('rating', 3, 1)->default(0);
                }

                if (! Schema::hasColumn('dramas', 'trending_score')) {
                    $table->unsignedInteger('trending_score')->default(0);
                }
            });
        }

        if (Schema::hasTable('episodes') && ! Schema::hasColumn('episodes', 'duration')) {
            Schema::table('episodes', function (Blueprint $table) {
                $table->unsignedInteger('duration')->default(0);
            });
        }
    }

    /**
     * Membuang kolom yang benar-benar ada saja.
     *
     * Basis data lama dan basis data baru tidak selalu punya kolom yang sama —
     * migrasi `ensure_catalog_admin_columns_exist` membuktikan itu. Memeriksa
     * satu per satu lebih murah daripada migrasi yang gagal di produksi.
     */
    private function dropColumnsIfExist(string $table, array $columns): void
    {
        $ada = array_values(array_filter(
            $columns,
            fn (string $kolom) => Schema::hasColumn($table, $kolom)
        ));

        if ($ada === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($ada) {
            $blueprint->dropColumn($ada);
        });
    }

    /**
     * Membuang index bila ada.
     *
     * Laravel tidak menyediakan Schema::hasIndex() lintas driver, jadi
     * pemeriksaannya dilakukan lewat information_schema untuk MySQL/MariaDB
     * dan lewat sqlite_master untuk SQLite (dipakai saat pengujian).
     */
    private function dropIndexIfExists(string $table, string $index): void
    {
        $driver = Schema::getConnection()->getDriverName();

        $ada = match ($driver) {
            'mysql', 'mariadb' => DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists(),
            'sqlite' => DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', $index)
                ->exists(),
            default => false,
        };

        if (! $ada) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropIndex($index);
        });
    }
};
