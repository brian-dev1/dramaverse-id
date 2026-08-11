<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua wilayah pembayaran: Indonesia dan luar Indonesia.
 *
 * ## Kenapa wilayah, bukan sekadar mata uang
 *
 * Yang membedakan keduanya bukan hanya angka di depan harga. Orang yang
 * membayar dari Malaysia tidak bisa memakai QRIS Indonesia sama sekali —
 * aplikasinya menolak kodenya. Jadi yang harus dipisah adalah pasangannya:
 * paket mana ditawarkan, dan lewat provider mana ia dibayar. Satu kolom
 * `currency` saja tidak bisa menjawab pertanyaan kedua.
 *
 * ## Kenapa bawaannya Indonesia
 *
 * Seluruh paket dan provider yang sudah ada dibuat untuk pasar Indonesia,
 * jadi `default('ID')` membuat baris lama tetap berarti persis seperti
 * sebelumnya. Migrasi yang mengubah arti data lama adalah migrasi yang
 * merusak diam-diam.
 *
 * Nilainya string 'ID'/'INTL', bukan boolean `is_international`. Wilayah
 * ketiga hampir pasti menyusul, dan kolom boolean adalah kolom yang harus
 * dibuang begitu jawabannya bukan lagi ya/tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->string('region', 10)->default('ID')->after('slug');

            // Disimpan per paket, bukan diturunkan dari wilayah. Wilayah
            // "luar Indonesia" suatu saat berisi paket Singapura berharga SGD
            // di samping paket Malaysia berharga MYR, dan menebak mata uang
            // dari wilayah akan salah pada hari itu.
            $table->char('currency', 3)->default('IDR')->after('price');

            // Dipakai menyaring daftar paket per wilayah di bot dan website.
            $table->index(['region', 'is_active']);
        });

        Schema::table('payment_providers', function (Blueprint $table) {
            $table->string('region', 10)->default('ID')->after('slug');

            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropIndex(['region', 'is_active']);
            $table->dropColumn(['region', 'currency']);
        });

        Schema::table('payment_providers', function (Blueprint $table) {
            $table->dropIndex(['region']);
            $table->dropColumn('region');
        });
    }
};
