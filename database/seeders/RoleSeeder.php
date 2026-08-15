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
            'Mengelola katalog: drama, part, genre, negara, dan banner.',
            // Editor mengunggah video, jadi ia juga yang perlu mengulang dan
            // membatalkan pekerjaan unggahnya sendiri. Memisahkannya ke peran
            // lain berarti setiap unggahan yang gagal harus menunggu orang
            // lain menekan tombol Retry.
            ['drama.view', 'drama.manage', 'episode.view', 'episode.manage', 'taxonomy.manage', 'report.view',
             'upload.view', 'upload.manage'],
        ],
        'moderator' => [
            'Moderator',
            'Mengelola pengguna dan bot Telegram. Tidak melihat data keuangan.',
            // `membership.manage` DICABUT dari moderator. Halaman paket,
            // langganan, metode bayar, dan tagihan memuat harga dan konfigurasi
            // pembayaran — informasi yang hanya boleh dipegang super admin.
            //
            // `report.view` tetap ada: laporan tontonan, konten, dan Telegram
            // bukan data keuangan. Angka rupiah di dalamnya disaring terpisah
            // lewat `finance.view`.
            ['user.view', 'user.manage', 'telegram.manage', 'report.view', 'log.view'],
        ],
        'viewer' => [
            'Pengamat',
            'Hanya dapat melihat data dan laporan, tanpa mengubah apa pun.',
            ['drama.view', 'episode.view', 'user.view', 'report.view', 'log.view'],
        ],
    ];

    /**
     * Izin yang dicabut dari peran bawaan non-super-admin saat seeder diulang.
     *
     * `permissions()->sync()` sudah menghapus izin yang tidak lagi terdaftar,
     * jadi daftar ini bukan untuk peran bawaan — melainkan pengingat eksplisit
     * bahwa peran buatan sendiri (dibuat lewat halaman Peran & Izin) TIDAK
     * disentuh seeder. Super admin yang pernah memberi `membership.manage` ke
     * peran kustom harus mencabutnya sendiri.
     */
    private const SENSITIF = ['finance.view', 'payment.manage', 'membership.manage'];

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

        $this->peringatkanPeranKustom();
    }

    /**
     * Laporkan peran buatan sendiri yang masih memegang izin sensitif.
     *
     * Seeder tidak mencabutnya otomatis — peran kustom adalah keputusan sadar
     * super admin, dan menghapusnya diam-diam saat deploy akan membuat orang
     * kehilangan akses tanpa tahu sebabnya. Cukup ditampilkan supaya keputusan
     * itu ditinjau ulang.
     */
    private function peringatkanPeranKustom(): void
    {
        $bawaan = array_keys(self::ROLES);

        $peran = Role::query()
            ->whereNotIn('slug', $bawaan)
            ->whereHas('permissions', fn ($q) => $q->whereIn('slug', self::SENSITIF))
            ->pluck('name');

        if ($peran->isEmpty()) {
            return;
        }

        $this->command?->warn(
            'Peran berikut masih memegang izin sensitif (pendapatan/metode bayar): '
            .$peran->join(', ').'. Tinjau di Admin → Peran & Izin.'
        );
    }
}
