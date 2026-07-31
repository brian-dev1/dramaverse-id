<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider pembayaran yang aktif, beserta kredensialnya.
 *
 * Bentuknya sengaja sama dengan `storage_providers` (7.1). Alasannya sama:
 * provider tidak boleh dipatok di kode. Menambah, mengganti, atau mematikan
 * provider adalah pekerjaan operator lewat panel, bukan pekerjaan deploy —
 * dan saat satu gateway bermasalah di tengah hari, menunggu deploy berarti
 * berjam-jam tanpa bisa menerima pembayaran.
 *
 * `credentials` disimpan terenkripsi lewat cast `encrypted:array` di model.
 * Server key gateway setara dengan kunci brankas: siapa pun yang memilikinya
 * bisa membuat transaksi atas nama kita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {

            $table->id();

            $table->string('name');

            $table->string('slug', 60);

            // Nilai enum PaymentDriver. String, bukan enum MySQL, supaya
            // menambah driver baru tidak memerlukan migration.
            $table->string('driver', 30);

            // Terenkripsi. Panjangnya tidak bisa diperkirakan karena setiap
            // driver punya jumlah field berbeda.
            $table->text('credentials')->nullable();

            // sandbox atau live. Dipisah dari kredensial supaya panel bisa
            // menandai dengan jelas provider mana yang masih uji coba —
            // provider sandbox yang tidak sengaja dijadikan default berarti
            // pembayaran sungguhan tidak pernah masuk.
            $table->string('mode', 10)->default('sandbox');

            $table->boolean('is_active')->default(false);

            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            // Biaya layanan yang ditanggung pengguna, dalam persen dan/atau
            // nominal tetap. Nol berarti tidak ada tambahan.
            $table->decimal('fee_percent', 5, 2)->default(0);

            $table->decimal('fee_flat', 12, 2)->default(0);

            $table->text('instruction')->nullable();

            $table->timestamps();

            $table->softDeletes();

            // Slug boleh dipakai ulang setelah provider dihapus, sama seperti
            // storage_providers di 7.2C.
            $table->unique(['slug', 'deleted_at']);

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
