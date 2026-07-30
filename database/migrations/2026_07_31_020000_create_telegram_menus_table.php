<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu tombol bot Telegram, supaya susunannya bisa diatur dari panel admin
 * tanpa menyunting kode.
 *
 * `row` menentukan baris keyboard, `position` menentukan urutan di dalam
 * baris. Dua tombol dengan row yang sama akan berdampingan. Keduanya sengaja
 * tidak diberi unique index: saat admin menukar urutan, keadaan sementara
 * yang bentrok itu wajar, dan menolaknya akan membuat penyuntingan biasa
 * gagal tanpa sebab yang masuk akal bagi yang mengerjakannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_menus', function (Blueprint $table) {

            $table->id();

            // Teks yang dilihat pengguna, termasuk emoji bila diinginkan.
            // Emoji diizinkan di sini — aturan larangan emoji proyek ini
            // berlaku untuk tampilan web, yang dirender peramban Windows.
            // Telegram merender emoji sendiri di semua sistem operasi.
            $table->string('label');

            // Nilai enum TelegramMenuAction. Disimpan sebagai string, bukan
            // enum MySQL, supaya menambah perbuatan baru tidak memerlukan
            // migration.
            $table->string('action', 32);

            // Hanya dipakai action `url`.
            $table->string('url')->nullable();

            $table->unsignedTinyInteger('row')->default(1);

            $table->unsignedTinyInteger('position')->default(1);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'row', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_menus');
    }
};
