<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_providers', function (Blueprint $table) {
            $table->string('qris_image_path')
                ->nullable()
                ->after('instruction');
        });
    }

    public function down(): void
    {
        Schema::table('payment_providers', function (Blueprint $table) {
            $table->dropColumn('qris_image_path');
        });
    }
};