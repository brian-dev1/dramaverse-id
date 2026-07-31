<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu percobaan pembayaran atas satu invoice.
 *
 * ## Dua kunci unik, dua persoalan berbeda
 *
 * `reference` adalah id yang KITA buat dan kirim ke provider. Uniknya menjaga
 * kita tidak pernah mengirim dua permintaan dengan id yang sama.
 *
 * `(payment_provider_id, external_id)` adalah id yang PROVIDER buat. Uniknya
 * menjaga callback ganda tidak melahirkan dua baris — dan callback ganda itu
 * normal, bukan kelainan: setiap provider mengirim ulang callback yang tidak
 * dijawab 200, dan jaringan tidak pernah menjamin jawabannya sampai.
 *
 * Tanpa unique kedua, satu pembayaran bisa mengaktifkan membership dua kali
 * dan memperpanjang masa aktifnya dua kali lipat.
 *
 * `signature` menyimpan tanda tangan callback yang sudah diverifikasi, supaya
 * sengketa belakangan bisa ditelusuri ke callback yang mana persisnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {

            $table->id();

            $table->foreignId('invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('payment_provider_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Id kita, dikirim ke provider.
            $table->string('reference', 64)->unique();

            // Id provider, dibaca dari callback atau dari jawaban charge.
            $table->string('external_id', 191)->nullable();

            $table->decimal('amount', 12, 2);

            $table->string('currency', 3)->default('IDR');

            $table->string('status', 20)->default('pending');

            $table->string('refund_status', 20)->default('none');

            // Metode yang akhirnya dipilih pengguna di halaman provider —
            // qris, va_bca, gopay. Datang dari callback, bukan dari kita.
            $table->string('method', 60)->nullable();

            // URL tempat pengguna menyelesaikan pembayaran.
            $table->string('checkout_url', 1000)->nullable();

            // Permintaan dan jawaban apa adanya, untuk penelusuran sengketa.
            // Kredensial TIDAK ikut: yang disimpan hanya payload transaksi.
            $table->json('request_payload')->nullable();

            $table->json('response_payload')->nullable();

            $table->string('signature', 191)->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->timestamp('verified_at')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->unsignedSmallInteger('verify_attempts')->default(0);

            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['payment_provider_id', 'external_id'], 'tx_provider_external_unique');

            $table->index('status');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
