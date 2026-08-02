<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('genres', 'icon')) {
            Schema::table('genres', function (Blueprint $table) {
                $table->string('icon', 32)->nullable()->after('description');
            });
        }

        if (! Schema::hasColumn('genres', 'color')) {
            Schema::table('genres', function (Blueprint $table) {
                $table->string('color', 7)->nullable()->after('icon');
            });
        }

        if (! Schema::hasColumn('countries', 'description')) {
            Schema::table('countries', function (Blueprint $table) {
                $table->string('description')->nullable()->after('code');
            });
        }
    }

    public function down(): void
    {
        $drop = array_values(array_filter(
            ['icon', 'color'],
            fn (string $column) => Schema::hasColumn('genres', $column)
        ));

        if ($drop !== []) {
            Schema::table('genres', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }

        if (Schema::hasColumn('countries', 'description')) {
            Schema::table('countries', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
