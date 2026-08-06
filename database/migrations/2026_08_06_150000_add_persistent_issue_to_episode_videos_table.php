<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_videos', function (Blueprint $table) {
            $table->text('issue_message')->nullable()->after('last_error');
            $table->timestamp('issue_detected_at')->nullable()->after('issue_message');
            $table->timestamp('issue_resolved_at')->nullable()->after('issue_detected_at');
            $table->text('issue_resolution')->nullable()->after('issue_resolved_at');

            $table->index('issue_resolved_at');
        });

        // Pertahankan problem lama yang masih tersimpan di last_error agar
        // notifikasinya tidak menghilang saat fitur persistent issue dipasang.
        DB::table('episode_videos')
            ->whereNotNull('last_error')
            ->whereNull('issue_message')
            ->update([
                'issue_message' => DB::raw('last_error'),
                'issue_detected_at' => DB::raw('COALESCE(updated_at, NOW())'),
            ]);
    }

    public function down(): void
    {
        Schema::table('episode_videos', function (Blueprint $table) {
            $table->dropIndex(['issue_resolved_at']);
            $table->dropColumn([
                'issue_message',
                'issue_detected_at',
                'issue_resolved_at',
                'issue_resolution',
            ]);
        });
    }
};
