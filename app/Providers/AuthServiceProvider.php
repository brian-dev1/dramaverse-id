<?php

namespace App\Providers;

use App\Models\Permission;
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
        'user.view'         => ['user', 'Lihat pengguna'],
        'user.manage'       => ['user', 'Kelola pengguna'],
        'telegram.manage'   => ['telegram', 'Broadcast dan status bot'],
        'report.view'       => ['report', 'Lihat laporan dan analytics'],
        'log.view'          => ['log', 'Lihat log aktivitas'],
        'setting.manage'    => ['setting', 'Ubah pengaturan situs'],
        'storage.view'      => ['storage', 'Lihat storage provider'],
        'role.manage'       => ['role', 'Kelola peran dan izin'],
    ];

    public function boot(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $slug) {
            Gate::define($slug, fn ($user) => $user->hasPermission($slug));
        }
    }
}
