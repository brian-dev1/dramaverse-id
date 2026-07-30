<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak sinkronisasi video episode ke Telegram.
 *
 * Kolomnya ditambahkan ke `episode_videos`, bukan ke tabel tersendiri. Satu
 * video punya tepat satu `file_id`, dan memisahkannya ke tabel lain berarti
 * setiap pembacaan butuh join untuk menjawab pertanyaan yang paling sering
 * diajukan: "berkas ini sudah ada di Telegram atau belum".
 *
 * `telegram_file_id` sengaja TIDAK unique. Telegram mengembalikan file_id
 * berbeda untuk bot yang berbeda pada berkas yang sama, dan mengunci
 * keunikannya di sini akan menolak penyimpanan yang sah bila suatu saat
 * bot-nya diganti.
 *
 * `telegram_unique_file_id` justru yang stabil lintas bot — itulah yang
 * dipakai untuk mengenali "ini berkas yang sama". Juga tidak unique, karena
 * dua episode boleh saja berisi berkas yang identik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_videos', function (Blueprint $table) {

            $table->string('telegram_file_id', 255)->nullable()->after('public_url');

            $table->string('telegram_unique_file_id', 100)->nullable()->after('telegram_file_id');

            // Pesan di chat penyimpanan. Disimpan supaya berkasnya bisa
            // ditelusuri kembali secara manual kalau file_id bermasalah.
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('telegram_unique_file_id');

            $table->string('sync_status', 20)->default('pending')->after('telegram_message_id');

            $table->timestamp('synced_at')->nullable()->after('sync_status');

            // Pesan galat dari Telegram bisa satu paragraf. TEXT, bukan
            // string: memotongnya menghilangkan bagian yang justru
            // menentukan.
            $table->text('last_error')->nullable()->after('synced_at');

            $table->unsignedSmallInteger('retry_count')->default(0)->after('last_error');

            $table->index('sync_status');
            $table->index('telegram_unique_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('episode_videos', function (Blueprint $table) {

            $table->dropIndex(['sync_status']);
            $table->dropIndex(['telegram_unique_file_id']);

            $table->dropColumn([
                'telegram_file_id',
                'telegram_unique_file_id',
                'telegram_message_id',
                'sync_status',
                'synced_at',
                'last_error',
                'retry_count',
            ]);
        });
    }
};
