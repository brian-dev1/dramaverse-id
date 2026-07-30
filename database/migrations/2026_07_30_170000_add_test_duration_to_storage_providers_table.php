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

            // Waktu respons Test Connection, dalam milidetik.
            //
            // Sprint 7.1 sudah menyimpan hasil dan pesan test terakhir, tapi
            // tidak durasinya — durasi hanya hidup selama satu permintaan.
            // Padahal justru angka inilah yang paling berguna dibandingkan
            // antar-provider: bucket yang jawabannya 40 ms dan yang 3 detik
            // sama-sama "berhasil", tapi hanya salah satunya layak dipakai
            // menyajikan video.
            //
            // unsignedInteger, bukan smallInteger: batas waktu bawaan 60 detik
            // berarti nilainya bisa mencapai 60000, jauh di atas 65535 hanya
            // kalau STORAGE_TIMEOUT dinaikkan — tapi memberi ruang di sini
            // jauh lebih murah daripada nilai yang dipotong diam-diam.
            $table->unsignedInteger('last_test_duration_ms')
                ->nullable()
                ->after('last_test_status');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {

            $table->dropColumn('last_test_duration_ms');

        });
    }
};
