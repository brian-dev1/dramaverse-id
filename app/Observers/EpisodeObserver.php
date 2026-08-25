<?php

namespace App\Observers;

use App\Models\Episode;

/**
 * Aturan akses part: part 1 gratis, part 2 dan seterusnya VIP.
 *
 * ## Kenapa observer, bukan di controller
 *
 * Ada beberapa jalur yang membuat dan mengubah baris `episodes`:
 *
 * - Form satuan admin (`EpisodeController::store/update`)
 * - Form massal per rentang (`EpisodeController::batchStore`)
 * - Pembuatan otomatis dari form drama (`DramaController::afterSave`)
 * - Seeder, tinker, dan perbaikan sesekali lewat artisan
 *
 * Menaruh aturannya di salah satu jalur berarti jalur lain diam-diam
 * tidak mengikutinya — dan tidak ada yang akan menyadarinya sampai ada
 * penonton gratis yang bisa menonton part 7, atau anggota VIP yang
 * kehilangan part 1. Observer menutup semuanya sekaligus.
 *
 * ## Kenapa `is_vip` yang diatur, bukan `canWatch()`
 *
 * `EpisodeAccessRepository::canWatch()` sudah benar dan tidak perlu
 * disentuh: `is_vip` false berarti terbuka untuk siapa pun. Menambahkan
 * pengecualian "part 1 selalu boleh" di sana akan membuat DATA dan
 * PERILAKU berbeda — daftar episode tetap menampilkan lencana VIP pada
 * part 1 yang sebenarnya bisa ditonton siapa saja. Lebih baik datanya
 * yang benar sejak awal, sehingga seluruh UI ikut benar tanpa diubah.
 *
 * ## Catatan
 *
 * Aturan ini MENIMPA pilihan admin. Itu memang yang diminta ("selalu
 * begitu"), dan karena itu setiap tempat yang dulu menawarkan pilihan
 * Gratis/VIP per part sudah diubah menjadi keterangan, bukan pilihan —
 * supaya tidak ada input yang diabaikan diam-diam.
 *
 * Aksi massal `$query->update()` melewati event model. Kalau nanti ada
 * aksi massal yang menyentuh `is_vip`, ia harus memanggil aturan ini
 * sendiri — atau memakai `php artisan episode:selaraskan-akses`.
 */
class EpisodeObserver
{
    public function creating(Episode $episode): void
    {
        $this->terapkan($episode);
    }

    public function updating(Episode $episode): void
    {
        $this->terapkan($episode);
    }

    /**
     * Nomor part menentukan aksesnya, titik.
     */
    private function terapkan(Episode $episode): void
    {
        $nomor = (int) $episode->episode_number;

        // Nomor yang tidak masuk akal dibiarkan apa adanya. Menebak akses
        // dari nomor 0 atau negatif hanya akan menyembunyikan data rusak,
        // dan validasi di lapisan atas yang seharusnya menangkapnya.
        if ($nomor < 1) {
            return;
        }

        $seharusnya = $nomor > 1;

        if ((bool) $episode->is_vip !== $seharusnya) {
            $episode->is_vip = $seharusnya;
        }
    }

    /**
     * Nilai `is_vip` yang benar untuk sebuah nomor part.
     *
     * Dipakai bersama oleh perintah penyelaras dan tampilan admin, supaya
     * aturannya hanya ditulis di satu tempat.
     */
    public static function seharusnyaVip(int $nomorPart): bool
    {
        return $nomorPart > 1;
    }
}
