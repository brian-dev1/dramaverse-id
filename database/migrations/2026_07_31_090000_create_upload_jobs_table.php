<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean unggah.
 *
 * Tabel ini BUKAN pengganti tabel `jobs` bawaan Laravel. Keduanya menyimpan
 * hal yang berbeda dan hidupnya berbeda pula:
 *
 * - `jobs` menyimpan payload yang belum dikerjakan. Barisnya HILANG begitu
 *   worker selesai. Tidak ada jejak apa pun yang tersisa untuk pekerjaan yang
 *   berhasil, dan itu memang bukan tugasnya.
 * - `upload_jobs` menyimpan riwayat yang bisa dilihat admin: apa yang
 *   diunggah, ke mana, oleh siapa, berhasil atau tidak, dan kalau gagal
 *   karena apa. Barisnya tetap ada setelah pekerjaannya selesai.
 *
 * Tanpa tabel ini, satu-satunya cara mengetahui nasib sebuah unggahan adalah
 * membuka `failed_jobs` dan membaca payload terserialisasi — yang tidak bisa
 * ditampilkan di panel dan tidak menyebut episode mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_jobs', function (Blueprint $table) {

            $table->id();

            // Identitas yang dipakai peramban untuk menanyakan status.
            //
            // Bukan id berurut, karena nilainya muncul di URL polling yang
            // dipanggil berkali-kali per unggahan. Id berurut di sana
            // membocorkan jumlah unggahan seluruh sistem kepada siapa pun yang
            // bisa membuka satu halaman panel.
            $table->uuid('uuid')->unique();

            // Jenis unggahan. Sprint ini hanya mengisi `episode_video`.
            //
            // Kolomnya tetap ada sejak awal supaya modul berikutnya (aset
            // drama, subtitle) bisa ikut memakai antrean yang sama tanpa
            // migration yang mengubah bentuk tabel yang sudah berisi data.
            $table->string('type', 40)->default('episode_video');

            $table->foreignId('episode_id')
                ->nullable()
                ->constrained('episodes')
                ->cascadeOnDelete();

            // Provider yang DIMINTA, bukan provider yang akhirnya dipakai.
            //
            // Kosong berarti mode Auto. Provider yang benar-benar menerima
            // berkasnya dicatat di `episode_videos` oleh EpisodeVideoService —
            // di sanalah kolom `storage_provider_id` yang mengikat berkas ke
            // bucket-nya, dan tidak ada gunanya menyalinnya ke sini.
            $table->foreignId('requested_provider_id')
                ->nullable()
                ->constrained('storage_providers')
                ->nullOnDelete();

            $table->string('storage_mode', 10)->default('auto');

            $table->string('status', 20)->default('pending');

            // --- Berkas ---

            $table->string('original_filename');

            $table->string('extension', 20)->nullable();

            $table->string('mime_type', 150);

            $table->unsignedBigInteger('size');

            // Lokasi berkas staging, relatif terhadap storage_path().
            //
            // Berkas sementara PHP dihapus begitu request selesai, jadi
            // berkasnya harus dipindahkan ke tempat yang bertahan sebelum
            // pekerjaannya diantrekan. Ini satu-satunya salinan yang ada
            // sampai unggahan ke provider berhasil.
            $table->string('staged_path', 500)->nullable();

            // --- Antrean ---

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->unsignedTinyInteger('max_attempts')->default(1);

            // Disalin saat pekerjaan diantrekan supaya halaman panel bisa
            // menyebut nama antrean yang harus didengarkan worker. Salah satu
            // penyebab "selamanya Pending" yang paling sering adalah worker
            // yang mendengarkan antrean lain.
            $table->string('queue_connection', 50)->nullable();

            $table->string('queue_name', 100)->nullable();

            // --- Hasil ---

            $table->foreignId('episode_video_id')
                ->nullable()
                ->constrained('episode_videos')
                ->nullOnDelete();

            $table->string('error_class', 255)->nullable();

            $table->text('error_message')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('queued_at')->nullable();

            $table->timestamp('started_at')->nullable();

            $table->timestamp('finished_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('type');
            $table->index('episode_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_jobs');
    }
};
