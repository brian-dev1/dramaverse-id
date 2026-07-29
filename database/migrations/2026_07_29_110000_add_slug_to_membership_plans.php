<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penanda tier yang stabil untuk paket membership.
 *
 * Sebelumnya tier hanya bisa ditebak dari kolom `name`, sehingga statistik
 * "anggota VIP" akan rusak begitu admin mengganti nama paket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->string('slug', 50)->nullable()->after('name');
            $table->unsignedInteger('sort_order')->default(0)->after('duration');
            $table->json('benefits')->nullable()->after('description');
            $table->string('badge', 30)->nullable()->after('benefits');
        });

        // Isi slug untuk baris yang sudah ada.
        foreach (DB::table('membership_plans')->get(['id', 'name']) as $plan) {
            DB::table('membership_plans')
                ->where('id', $plan->id)
                ->update(['slug' => Str::slug($plan->name)]);
        }

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'sort_order', 'benefits', 'badge']);
        });
    }
};
