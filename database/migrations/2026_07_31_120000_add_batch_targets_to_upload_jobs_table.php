<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tujuan kedua untuk antrean unggah: aset drama, dan pengelompokan batch.
 *
 * Tabel `upload_jobs` dibuat di Sprint 7.7 dengan satu tujuan saja — video
 * episode — tetapi kolom `type` sudah disiapkan sejak awal justru untuk saat
 * ini. Catatan di migration aslinya berbunyi: "kolomnya tetap ada sejak awal
 * supaya modul berikutnya (aset drama, subtitle) bisa ikut memakai antrean
 * yang sama tanpa migration yang mengubah bentuk tabel yang sudah berisi
 * data." Migration ini menambah kolom, tidak mengubah satu pun yang ada.
 *
 * ## Kenapa kolom tujuan tidak digabung jadi satu pasang polymorphic
 *
 * `target_type` + `target_id` akan lebih pendek, tetapi menghilangkan foreign
 * key. Tanpa foreign key, menghapus sebuah drama meninggalkan baris antrean
 * yang menunjuk drama yang tidak ada, dan tidak ada apa pun yang mencegahnya
 * — sementara `episode_id` yang sudah ada di tabel ini justru ikut terhapus
 * lewat cascade. Dua bentuk penanganan yang berbeda pada satu tabel yang sama
 * adalah sumber kebingungan yang tidak sebanding dengan dua kolom yang
 * dihemat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_jobs', function (Blueprint $table) {

            /*
             * Pengenal satu batch.
             *
             * Berkas-berkas yang dikirim dalam satu kali "Unggah" berbagi nilai
             * ini. Diperlukan karena setiap berkas menjadi PEKERJAAN SENDIRI —
             * itulah cara "kalau satu gagal, yang lain tetap diproses"
             * diwujudkan — sehingga tanpa penanda ini tidak ada yang tahu
             * bahwa dua puluh baris itu sebenarnya satu tindakan admin.
             *
             * Tidak unik: banyak baris memang berbagi satu nilai. Tetapi
             * berindeks, karena halaman batch menanyakannya berulang kali
             * selama unggahan berjalan.
             */
            $table->uuid('batch_uuid')->nullable()->after('uuid')->index();

            /*
             * Drama tujuan, untuk pekerjaan bertipe `drama_asset`.
             *
             * `cascadeOnDelete` menyamai perlakuan `episode_id`: menghapus
             * drama menghapus riwayat unggahan asetnya. Riwayat yang menunjuk
             * drama yang sudah tidak ada tidak bisa dibaca siapa pun — tidak
             * ada judul, tidak ada halaman yang bisa dibuka darinya.
             */
            $table->foreignId('drama_id')
                ->nullable()
                ->after('episode_id')
                ->constrained('dramas')
                ->cascadeOnDelete();

            /*
             * Jenis aset yang diminta: nilai dari `DramaAssetType`.
             *
             * Disimpan sebagai string, bukan foreign key, karena daftarnya
             * hidup di enum PHP dan bukan di tabel referensi — sama seperti
             * kolom `asset_type` di `drama_assets`.
             */
            $table->string('asset_type', 32)->nullable()->after('storage_mode');

            /*
             * Baris aset yang DIHASILKAN pekerjaan ini.
             *
             * Pasangan dari `episode_video_id` yang sudah ada. `nullOnDelete`,
             * bukan cascade: menghapus asetnya lewat File Manager tidak boleh
             * ikut menghapus catatan bahwa unggahan itu pernah berhasil.
             */
            $table->foreignId('drama_asset_id')
                ->nullable()
                ->after('episode_video_id')
                ->constrained('drama_assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('upload_jobs', function (Blueprint $table) {

            // Foreign key dilepas sebelum kolomnya dibuang. MySQL menolak
            // menghapus kolom yang masih dipegang sebuah constraint, dan
            // galatnya menyebut nama constraint yang tidak pernah ditulis
            // siapa pun secara eksplisit.
            $table->dropConstrainedForeignId('drama_id');
            $table->dropConstrainedForeignId('drama_asset_id');

            $table->dropIndex(['batch_uuid']);

            $table->dropColumn(['batch_uuid', 'asset_type']);
        });
    }
};
