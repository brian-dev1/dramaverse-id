<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->bigInteger('telegram_id')
                ->nullable()
                ->unique()
                ->after('id');

            $table->string('telegram_username')
                ->nullable()
                ->after('telegram_id');

            $table->string('telegram_first_name')
                ->nullable()
                ->after('telegram_username');

            $table->string('telegram_last_name')
                ->nullable()
                ->after('telegram_first_name');

        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropColumn([
                'telegram_id',
                'telegram_username',
                'telegram_first_name',
                'telegram_last_name',
            ]);

        });
    }
};