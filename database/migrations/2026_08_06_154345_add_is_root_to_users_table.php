<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_root')
                ->default(false)
                ->after('is_admin')
                ->index();
        });

        /*
         * Akun administrator bawaan DramaVerse menjadi Root Owner.
         *
         * Email hanya digunakan SEKALI pada saat migrasi untuk menemukan
         * akun existing. Setelah itu aplikasi mengenali Root Owner melalui
         * kolom is_root, bukan melalui email ataupun ID tertentu.
         */
        DB::table('users')
            ->where('email', 'admin@dramaverse.id')
            ->where('is_admin', true)
            ->update([
                'is_root'   => true,
                'is_active' => true,
                'is_banned' => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_root');
        });
    }
};