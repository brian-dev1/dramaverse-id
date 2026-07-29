<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom tambahan yang dibutuhkan panel admin untuk mengelola taksonomi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            // Nama ikon dari komponen <x-web.home.icon>, bukan emoji.
            $table->string('icon', 32)->nullable()->after('description');
            // Warna aksen heksadesimal, mis. #D9AF6E
            $table->string('color', 7)->nullable()->after('icon');
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->string('description')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->dropColumn(['icon', 'color']);
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
