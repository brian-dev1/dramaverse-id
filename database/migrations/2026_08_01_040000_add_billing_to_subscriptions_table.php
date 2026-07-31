<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan langganan ke tagihan yang membayarnya.
 *
 * Sebelum Phase 10, `subscriptions` hanya punya `payment_reference` berupa
 * string bebas yang tidak menunjuk ke mana pun. Kolom itu dipertahankan —
 * baris lama memakainya — tetapi yang baru memakai `invoice_id`.
 *
 * `auto_renew` disiapkan penuh strukturnya; yang memperpanjang otomatis saat
 * jatuh tempo belum ada, dan itu disebut terbuka di dokumen sprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {

            $table->foreignId('invoice_id')
                ->nullable()
                ->after('membership_plan_id')
                ->constrained()
                ->nullOnDelete();

            $table->boolean('auto_renew')->default(false)->after('status');

            $table->timestamp('cancelled_at')->nullable()->after('expired_at');

            // manual, checkout, admin. Menjawab "dari mana langganan ini
            // datang" saat ada yang menanyakannya berbulan-bulan kemudian.
            $table->string('source', 20)->default('checkout')->after('auto_renew');

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['auto_renew', 'cancelled_at', 'source']);
        });
    }
};
