<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan: satu niat membeli satu paket membership.
 *
 * Invoice dan transaksi sengaja DIPISAH. Satu invoice bisa punya beberapa
 * percobaan pembayaran — pengguna yang gagal bayar lalu mencoba lagi dengan
 * provider berbeda tidak boleh kehilangan riwayat percobaan pertamanya, dan
 * menimpanya berarti tidak ada yang bisa menjawab "kenapa dia bilang sudah
 * bayar tapi tidak masuk".
 *
 * `number` adalah id yang dilihat manusia dan disebut di percakapan dukungan.
 * Unik, dan tidak pernah dipakai ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {

            $table->id();

            $table->string('number', 40)->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Paket bisa dihapus admin sementara invoicenya harus tetap ada
            // sebagai bukti transaksi. Karena itu nullOnDelete, dan nama
            // serta harga paket ikut disalin ke bawah.
            $table->foreignId('membership_plan_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Salinan keadaan saat dibeli. Harga paket bisa berubah; invoice
            // lama harus tetap menunjukkan yang benar-benar dibayar.
            $table->string('plan_name');

            $table->unsignedInteger('plan_duration');

            $table->decimal('subtotal', 12, 2);

            $table->decimal('fee', 12, 2)->default(0);

            $table->decimal('total', 12, 2);

            $table->string('currency', 3)->default('IDR');

            $table->string('status', 20)->default('pending');

            $table->timestamp('due_at')->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
