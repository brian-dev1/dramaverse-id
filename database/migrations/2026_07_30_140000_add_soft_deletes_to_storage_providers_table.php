<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {

            // Menghapus storage provider berbeda bobotnya dari menghapus genre.
            // Barisnya memuat kredensial dan pemetaan ke bucket tempat berkas
            // sungguhan berada — berkasnya tidak ikut terhapus, tapi tanpa baris
            // ini aplikasi kehilangan satu-satunya jalan untuk menjangkaunya.
            // Soft delete membuat kekeliruan itu bisa dibatalkan.
            $table->softDeletes();

        });

        Schema::table('storage_providers', function (Blueprint $table) {

            // Unique tunggal pada slug menghalangi pemakaian ulang slug setelah
            // provider dihapus: barisnya masih ada, hanya ditandai terhapus.
            // Menghapus provider `r2` yang salah konfigurasi lalu membuatnya
            // ulang dengan slug yang sama adalah alur yang wajar, jadi jaminan
            // keunikan dipindah ke gabungan (slug, deleted_at).
            //
            // MySQL menganggap NULL berbeda satu sama lain pada index unique.
            // Artinya banyak baris terhapus boleh memakai slug yang sama,
            // sementara baris hidup (deleted_at NULL) tetap dijamin hanya satu
            // per slug — yang memang satu-satunya jaminan yang dibutuhkan,
            // karena StorageManager hanya pernah mencari baris hidup.
            $table->dropUnique('storage_providers_slug_unique');

            $table->unique(['slug', 'deleted_at']);

        });
    }

    /**
     * Reverse the migrations.
     *
     * Catatan: mengembalikan unique tunggal akan GAGAL bila ada dua baris
     * terhapus yang slug-nya sama — keadaan yang justru sah setelah migration
     * ini berjalan. Kosongkan baris terhapus lebih dulu bila perlu rollback.
     */
    public function down(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {

            $table->dropUnique('storage_providers_slug_deleted_at_unique');

            $table->unique('slug');

        });

        Schema::table('storage_providers', function (Blueprint $table) {

            $table->dropSoftDeletes();

        });
    }
};
