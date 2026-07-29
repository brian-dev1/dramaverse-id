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
        Schema::create('storage_providers', function (Blueprint $table) {

            $table->id();

            $table->string('name');

            // Kunci pemanggilan dari kode: Storage disk dibangun per slug,
            // bukan per id, supaya konfigurasi tetap terbaca kalau baris
            // dihapus dan dibuat ulang.
            $table->string('slug')->unique();

            $table->string('driver', 32);

            $table->string('bucket')->nullable();

            $table->string('endpoint')->nullable();

            $table->string('region', 64)->nullable();

            // TEXT, bukan VARCHAR: nilainya disimpan terenkripsi lewat cast
            // `encrypted` di model, dan hasil enkripsi Laravel jauh lebih
            // panjang daripada kunci aslinya.
            $table->text('access_key')->nullable();

            $table->text('secret_key')->nullable();

            // Prefix folder di dalam bucket, mis. "video" atau "dramaverse/prod".
            $table->string('root')->nullable();

            // Domain publik atau CDN di depan bucket. Kalau kosong, URL
            // disusun oleh adapter — yang untuk R2 dan B2 hampir selalu salah,
            // jadi kolom ini praktis wajib untuk provider tersebut.
            $table->string('public_url')->nullable();

            $table->string('visibility', 20)->default('private');

            $table->boolean('use_path_style')->default(false);

            // Opsi tambahan yang khas satu provider dan tidak layak jadi
            // kolom sendiri, mis. checksum_calculation atau throw.
            $table->json('options')->nullable();

            $table->string('status', 20)->default('inactive');

            // Angka lebih KECIL dicoba lebih dulu. Provider lokal diberi
            // angka besar supaya jadi pilihan terakhir.
            $table->unsignedSmallInteger('priority')->default(100);

            $table->boolean('is_default')->default(false);

            // Jejak Test Connection terakhir. Disimpan supaya admin bisa
            // melihat kapan provider terakhir terbukti bisa dihubungi tanpa
            // harus menjalankan test lagi.
            $table->timestamp('last_tested_at')->nullable();

            $table->string('last_test_status', 20)->nullable();

            $table->text('last_test_message')->nullable();

            $table->timestamps();

            // Rantai fallback membaca status + priority bersamaan.
            $table->index(['status', 'priority']);

            $table->index('is_default');

            $table->index('driver');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_providers');
    }
};
