<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Providers\AuthServiceProvider;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /** Peran bawaan: slug => [nama, keterangan, izin]. */
    private const ROLES = [
        Role::SUPER_ADMIN => [
            'Super Admin',
            'Akses penuh ke seluruh panel, termasuk pengaturan dan peran.',
            '*',
        ],
        'editor' => [
            'Editor Konten',
            'Mengelola katalog: drama, episode, genre, negara, dan banner.',
            // Editor mengunggah video, jadi ia juga yang perlu mengulang dan
            // membatalkan pekerjaan unggahnya sendiri. Memisahkannya ke peran
            // lain berarti setiap unggahan yang gagal harus menunggu orang
            // lain menekan tombol Retry.
            ['drama.view', 'drama.manage', 'episode.view', 'episode.manage', 'taxonomy.manage', 'report.view',
             'upload.view', 'upload.manage'],
        ],
        'moderator' => [
            'Moderator',
            'Mengelola pengguna dan langganan, tanpa mengubah katalog.',
            ['user.view', 'user.manage', 'membership.manage', 'telegram.manage', 'report.view', 'log.view'],
        ],
        'viewer' => [
            'Pengamat',
            'Hanya dapat melihat data dan laporan, tanpa mengubah apa pun.',
            ['drama.view', 'episode.view', 'user.view', 'report.view', 'log.view'],
        ],
    ];

    public function run(): void
    {
        // --- Izin ---
        foreach (AuthServiceProvider::PERMISSIONS as $slug => [$module, $name]) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'module' => $module]
            );
        }

        $all = Permission::pluck('id', 'slug');

        // --- Peran ---
        foreach (self::ROLES as $slug => [$name, $description, $permissions]) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description]
            );

            $ids = $permissions === '*'
                ? $all->values()->all()
                : collect($permissions)->map(fn ($p) => $all[$p] ?? null)->filter()->values()->all();

            $role->permissions()->sync($ids);
        }
    }
}
