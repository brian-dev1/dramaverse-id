<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak peristiwa satu pekerjaan unggah.
 *
 * Spesifikasi meminta "Log setiap proses upload". Semuanya sudah masuk
 * `storage/logs/laravel.log` lewat Log::channel — tetapi berkas log ada di
 * server, hanya bisa dibaca lewat SSH, dan berisi seluruh aktivitas aplikasi
 * bercampur jadi satu. Admin yang ingin tahu kenapa satu unggahan gagal tidak
 * seharusnya perlu SSH.
 *
 * Tabel ini menyimpan peristiwa yang SAMA, disaring pada satu pekerjaan, agar
 * bisa dibuka di panel bersama barisnya. Log berkas tetap ditulis dan tetap
 * menjadi sumber yang lengkap; yang di sini adalah salinan yang bisa dilihat.
 *
 * Ini bukan monitoring. Tidak ada agregasi, tidak ada grafik, tidak ada
 * peringatan — hanya daftar peristiwa milik satu baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_job_logs', function (Blueprint $table) {

            $table->id();

            // Log ikut terhapus bersama pekerjaannya. Log tanpa pekerjaan
            // tidak bisa dibaca siapa pun — tidak ada episode, tidak ada
            // berkas, tidak ada konteks.
            $table->foreignId('upload_job_id')
                ->constrained('upload_jobs')
                ->cascadeOnDelete();

            // info | warning | error
            $table->string('level', 10)->default('info');

            // queued | started | success | failed | retried | cancelled |
            // deleted | staged | orphan
            $table->string('event', 60);

            $table->text('message')->nullable();

            // Konteks tambahan: provider, ukuran, durasi, kelas exception.
            //
            // JSON dan bukan kolom terpisah karena isinya berbeda-beda per
            // peristiwa, dan menambah kolom untuk setiap peristiwa baru akan
            // menghasilkan tabel yang sebagian besar kolomnya selalu kosong.
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(['upload_job_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_job_logs');
    }
};
