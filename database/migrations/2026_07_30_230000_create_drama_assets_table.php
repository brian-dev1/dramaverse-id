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
        Schema::create('drama_assets', function (Blueprint $table) {

            $table->id();

            // Drama dihapus, asetnya ikut hilang dari database. Berkas di
            // bucket TIDAK ikut terhapus oleh cascade ini — pembersihannya
            // dilakukan service sebelum drama dihapus, atau tertinggal sebagai
            // sisa yang dicatat di log.
            $table->foreignId('drama_id')
                ->constrained('dramas')
                ->cascadeOnDelete();

            $table->string('asset_type', 32);

            // Sama seperti episode_videos: restrict, bukan cascade maupun null.
            //
            // Provider memakai soft delete sehingga FK ini tetap sah. Kalau
            // suatu hari ada penghapusan permanen, penghapusan itu harus
            // DITOLAK selama masih ada aset yang menunjuknya — mengosongkan
            // kolom ini berarti kehilangan satu-satunya petunjuk di bucket
            // mana berkasnya berada.
            $table->foreignId('storage_provider_id')
                ->constrained('storage_providers')
                ->restrictOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('disk', 100);

            $table->string('bucket')->nullable();

            $table->string('object_key', 900);

            $table->string('directory', 500)->nullable();

            $table->string('original_filename');

            $table->string('stored_filename');

            $table->string('extension', 20)->nullable();

            $table->string('mime_type', 150);

            $table->unsignedBigInteger('size');

            $table->char('checksum', 64);

            $table->string('public_url', 1000)->nullable();

            // Urutan tampil, dipakai galeri. Jenis lain selalu 0 karena hanya
            // punya satu berkas.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // Index gabungan: hampir setiap query modul ini berbentuk
            // "aset jenis X milik drama Y", dan galeri menambahkan urutan.
            $table->index(['drama_id', 'asset_type', 'sort_order'], 'drama_assets_lookup_index');

            $table->index('storage_provider_id');
            $table->index('checksum');

            /*
             * CATATAN JUJUR — keunikan "satu berkas per jenis" TIDAK dijaga
             * database.
             *
             * Sembilan dari sepuluh jenis hanya boleh punya satu berkas, tetapi
             * `gallery` boleh banyak. Aturan "unik kecuali untuk satu nilai"
             * memerlukan partial index, yang tidak ada di MySQL. Bisa disiasati
             * dengan kolom generated + unique index, seperti yang dicatat untuk
             * storage default — dan seperti di sana, sintaksnya berbeda antar
             * versi MySQL dan tidak bisa saya uji dari sini.
             *
             * Jadi yang menjaganya adalah DramaAssetService, lewat
             * updateOrCreate pada (drama_id, asset_type) untuk jenis tunggal.
             * Selama semua penulisan lewat service itu, aturannya berlaku.
             */
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drama_assets');
    }
};
