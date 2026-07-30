<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('episode_videos', function (Blueprint $table) {

            $table->id();

            // Satu video per episode. Mengunggah lagi MENGGANTI baris ini,
            // bukan menambah baris kedua — kalau tidak, tidak ada yang bisa
            // menjawab "video mana yang sedang dipakai episode ini".
            $table->foreignId('episode_id')
                ->unique()
                ->constrained('episodes')
                ->cascadeOnDelete();

            // TIDAK cascadeOnDelete, dan TIDAK nullOnDelete.
            //
            // Provider memakai soft delete, jadi barisnya tetap ada dan FK ini
            // tetap sah. Yang penting: kalau suatu hari provider dihapus
            // permanen, penghapusan itu harus DITOLAK selama masih ada video
            // yang menunjuknya. Mengosongkan kolom ini berarti kehilangan
            // satu-satunya petunjuk di bucket mana berkasnya berada — key-nya
            // masih tersimpan, tapi tidak ada lagi yang tahu ke mana harus
            // mencarinya.
            $table->foreignId('storage_provider_id')
                ->constrained('storage_providers')
                ->restrictOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Identitas disk pada saat diunggah, yaitu slug provider.
            // Sengaja disalin, bukan hanya diandalkan lewat relasi: slug
            // provider bisa diubah admin, dan baris ini harus tetap
            // memberi tahu ke mana berkasnya dulu dikirim.
            $table->string('disk', 100);

            $table->string('bucket')->nullable();

            $table->string('object_key', 900);

            // Turunan dari object_key, disimpan terpisah supaya bisa
            // dikelompokkan dan diindeks tanpa memotong string di query.
            $table->string('directory', 500)->nullable();

            $table->string('original_filename');

            $table->string('stored_filename');

            $table->string('extension', 20)->nullable();

            $table->string('mime_type', 150);

            $table->unsignedBigInteger('size');

            // SHA256 selalu 64 karakter heksadesimal.
            $table->char('checksum', 64);

            $table->string('public_url', 1000)->nullable();

            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            $table->index('storage_provider_id');
            $table->index('checksum');
            $table->index('uploaded_at');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episode_videos');
    }
};
