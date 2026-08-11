<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Mendaftarkan seluruh izin sebagai Gate.
 *
 * Dengan begini `@can('drama.create')` di Blade dan `$this->authorize()`
 * di controller bekerja tanpa perlu menulis Policy per entitas.
 */
class AuthServiceProvider extends ServiceProvider
{
    /** Daftar izin bawaan: slug => [modul, label]. */
    public const PERMISSIONS = [
        'drama.view'        => ['drama', 'Lihat drama'],
        'drama.manage'      => ['drama', 'Kelola drama'],
        'episode.view'      => ['episode', 'Lihat episode'],
        'episode.manage'    => ['episode', 'Kelola episode'],
        'taxonomy.manage'   => ['taxonomy', 'Kelola genre, negara, banner'],
        'membership.manage' => ['membership', 'Kelola paket dan langganan'],

        // --- Izin sensitif (lihat SENSITIVE di bawah) ---
        //
        // `membership.manage` sengaja TIDAK dipecah menjadi view/manage.
        // Halaman paket dan langganan memuat harga di setiap barisnya, jadi
        // "boleh melihat tapi tidak boleh mengubah" tetap membocorkan angka
        // yang justru ingin disembunyikan.
        'finance.view'      => ['finance', 'Lihat pendapatan dan angka keuangan'],
        'payment.manage'    => ['payment', 'Kelola metode bayar dan verifikasi pembayaran'],

        'user.view'         => ['user', 'Lihat pengguna'],
        'user.manage'       => ['user', 'Kelola pengguna'],

        // Dipisahkan dari user.manage karena akun admin memiliki hak akses
        // ke panel dan jauh lebih sensitif daripada pengguna biasa.
        'admin.manage'      => ['admin', 'Kelola akun admin'],

        'telegram.manage'   => ['telegram', 'Broadcast dan status bot'],
        'report.view'       => ['report', 'Lihat laporan dan analytics'],
        'log.view'          => ['log', 'Lihat log aktivitas'],
        'setting.manage'    => ['setting', 'Ubah pengaturan situs'],
        'storage.view'      => ['storage', 'Lihat storage provider'],
        'storage.manage'    => ['storage', 'Tambah dan ubah storage provider'],
        'upload.view'       => ['upload', 'Lihat antrean unggah'],
        'upload.manage'     => ['upload', 'Ulangi, batalkan, dan hapus pekerjaan unggah'],
        'role.manage'       => ['role', 'Kelola peran dan izin'],
    ];

    /**
     * Izin yang memegang informasi bisnis paling sensitif.
     *
     * Dua hal membedakannya dari izin biasa:
     *
     * 1. `User::hasPermission()` memberi seluruh izin kepada akun admin yang
     *    belum punya role sama sekali — kompatibilitas dengan instalasi lama.
     *    Kelonggaran itu TIDAK berlaku di sini. Tanpa pengecualian ini, cukup
     *    dengan tidak memberi role apa pun, sebuah akun admin baru akan
     *    langsung melihat seluruh angka pendapatan — persis kebocoran yang
     *    ingin ditutup.
     *
     * 2. RoleSeeder tidak pernah memberikannya ke peran bawaan selain
     *    super-admin. Super admin boleh mendelegasikannya belakangan lewat
     *    halaman Peran & Izin, tapi itu harus keputusan sadar.
     *
     * @var list<string>
     */
    public const SENSITIVE = [
        'finance.view',
        'payment.manage',
        'membership.manage',
    ];

    public static function isSensitive(string $slug): bool
    {
        return in_array($slug, self::SENSITIVE, true);
    }

    public function boot(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $slug) {
            Gate::define($slug, fn ($user) => $user->hasPermission($slug));
        }
    }
}