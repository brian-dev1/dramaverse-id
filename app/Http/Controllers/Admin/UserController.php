<?php

namespace App\Http\Controllers\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Admin\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends AdminCrudController
{
    protected function model(): string
    {
        return User::class;
    }

    protected function routeKey(): string
    {
        return 'user';
    }

    protected function label(): string
    {
        return 'Pengguna';
    }

    protected function columns(): array
    {
        return [
            'Nama'           => 'name',
            'Telegram'       => 'telegram_username',
            'Tontonan'       => 'watch_histories_count',
            'Aktif'          => 'is_active',
            'Diblokir'       => 'is_banned',
            'Terakhir masuk' => 'last_login_at',
            'Bergabung'      => 'created_at',
        ];
    }

    protected function sortable(): array
    {
        return ['name', 'last_login_at', 'last_seen_at', 'created_at'];
    }

    protected function searchable(): array
    {
        return ['name', 'telegram_username', 'email'];
    }

    protected function withCount(): array
    {
        return ['watchHistories'];
    }

    protected function filters(): array
    {
        return [
            'is_active' => ['label' => 'Status', 'options' => [1 => 'Aktif', 0 => 'Nonaktif']],
            'is_banned' => ['label' => 'Blokir', 'options' => [1 => 'Diblokir', 0 => 'Normal']],
            'is_admin'  => ['label' => 'Peran',  'options' => [1 => 'Admin', 0 => 'Pengguna']],
        ];
    }

    /** Pengguna dibuat oleh bot Telegram, bukan lewat form admin. */
    protected function rules(Request $request, ?Model $model = null): array
    {
        return [];
    }

    protected function bulkActions(): array
    {
        return [
            'activate'   => 'Aktifkan',
            'deactivate' => 'Nonaktifkan',
            'ban'        => 'Blokir',
            'unban'      => 'Buka blokir',
        ];
    }

    protected function applyBulk(string $action, Builder $query): int
    {
        // Admin tidak boleh terkena aksi massal — termasuk diri sendiri.
        $query->where('is_admin', false);

        return match ($action) {
            'activate'   => $query->update(['is_active' => true]),
            'deactivate' => $query->update(['is_active' => false]),
            'ban'        => $query->update(['is_banned' => true, 'is_active' => false]),
            'unban'      => $query->update(['is_banned' => false, 'is_active' => true]),
            default      => 0,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Detail pengguna
    |--------------------------------------------------------------------------
    */

    public function show(int $id): View
    {
        $user = User::query()
            ->withCount(['watchHistories', 'favorites', 'watchlists'])
            ->findOrFail($id);

        return view('web.pages.admin.user-detail', [
            'user' => $user,

            // Dipakai kotak "Akses Admin". Diambil selalu, bukan hanya saat
            // pengguna ini admin: kotaknya juga dipakai untuk MENAIKKAN
            // pengguna biasa, dan saat itu daftar rolenya justru yang paling
            // dibutuhkan.
            'roles' => Role::query()->orderBy('name')->get(),

            'histories' => $user->watchHistories()
                ->with(['drama:id,title,slug', 'episode:id,episode_number'])
                ->whereHas('drama')
                ->latest('last_watched_at')
                ->take(20)
                ->get(),

            'favorites' => $user->favorites()
                ->with('drama:id,title,slug')
                ->whereHas('drama')
                ->latest()
                ->take(20)
                ->get(),

            'watchlists' => $user->watchlists()
                ->with('drama:id,title,slug')
                ->whereHas('drama')
                ->latest()
                ->take(20)
                ->get(),

            'subscriptions' => $user->subscriptions()
                ->with('plan:id,name')
                ->latest()
                ->get(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Aksi
    |--------------------------------------------------------------------------
    */

    public function toggleBan(int $id): RedirectResponse
    {
        $user = $this->guardedUser($id);

        $banned = ! $user->is_banned;

        $user->update([
            'is_banned' => $banned,
            'is_active' => ! $banned,
        ]);

        app(ActivityLogger::class)->log($banned ? 'diblokir' : 'dibuka blokirnya', 'user', $user);

        return back()->with('status', $banned
            ? 'Pengguna diblokir dan tidak bisa masuk lagi.'
            : 'Blokir pengguna dibuka.');
    }

    public function toggleActive(int $id): RedirectResponse
    {
        $user = $this->guardedUser($id);

        $user->update(['is_active' => ! $user->is_active]);

        app(ActivityLogger::class)->log($user->is_active ? 'diaktifkan' : 'dinonaktifkan', 'user', $user);

        return back()->with('status', $user->is_active
            ? 'Pengguna diaktifkan.'
            : 'Pengguna dinonaktifkan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->guardedUser($id);

        return parent::destroy($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Akses admin
    |--------------------------------------------------------------------------
    |
    | Panel membuat akun admin dengan email dan password, dan komentarnya
    | menyatakannya sendiri: "Admin yang dibuat melalui panel bukan akun
    | Telegram." Itu benar untuk admin yang bekerja di panel — tetapi menutup
    | satu hal yang diperlukan sejak bot punya perintah admin: memberi akses
    | kepada orang yang identitasnya MEMANG akun Telegram.
    |
    | Menyalin `telegram_id` ke akun admin terpisah bukan jalan keluar; kolom
    | itu unik, dan barisnya sudah dipakai akun Telegram orang tersebut.
    | Menaikkan akun yang sudah ada jauh lebih sederhana, dan menjaga riwayat,
    | referral, serta langganannya tetap menempel pada satu orang.
    */

    /**
     * Jadikan pengguna ini admin, dengan role yang dipilih.
     *
     * ## Kenapa role WAJIB dipilih
     *
     * `User::hasPermission()` memperlakukan admin tanpa role sebagai pemegang
     * SELURUH izin yang tidak sensitif. Menaikkan seseorang tanpa role karena
     * itu diam-diam membuka hampir seluruh panel — persis kebalikan dari yang
     * dimaksud orang yang cuma ingin memberi akses satu perintah bot.
     *
     * Mewajibkannya membuat pemberian akses selalu merupakan keputusan yang
     * disebutkan, bukan akibat sampingan.
     */
    public function promote(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(
            [
                'roles'   => ['required', 'array', 'min:1'],
                'roles.*' => ['integer', 'exists:roles,id'],
            ],
            ['roles.required' => 'Pilih minimal satu role untuk pengguna ini.']
        );

        $user = User::findOrFail($id);

        abort_if(
            $user->id === Auth::id(),
            403,
            'Anda tidak bisa mengubah akses akun Anda sendiri di sini.'
        );

        $user->forceFill([
            'is_admin' => true,

            // Admin yang nonaktif tidak bisa membuka panel maupun memakai
            // perintah bot — `canAccessAdmin()` memeriksanya. Menaikkan
            // seseorang lalu membiarkannya nonaktif menghasilkan akses yang
            // tidak pernah bekerja, tanpa petunjuk kenapa.
            'is_active' => true,
        ])->save();

        $user->roles()->sync(
            Role::query()->whereIn('id', $data['roles'])->pluck('id')->all()
        );

        app(ActivityLogger::class)->log('dijadikan admin', 'user', $user);

        return back()->with('status',
            $user->display_name.' sekarang admin. Aksesnya mengikuti role yang dipilih, '
            .'baik di panel maupun di perintah bot.');
    }

    /**
     * Cabut status admin.
     *
     * Role ikut dilepas, bukan disimpan "kalau-kalau dinaikkan lagi". Role
     * yang tertinggal pada akun non-admin adalah izin yang tidak terlihat di
     * mana pun sampai seseorang menaikkannya kembali — dan pada saat itu ia
     * mendapat akses yang tidak pernah diputuskan siapa pun.
     */
    public function demote(int $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        abort_if(
            $user->id === Auth::id(),
            403,
            'Anda tidak bisa mencabut akses akun Anda sendiri.'
        );

        // Root Owner tidak bergantung pada role, jadi mencabut is_admin saja
        // tidak menutup aksesnya — hanya membuat keadaannya membingungkan.
        abort_if(
            $user->isRoot(),
            403,
            'Root Owner tidak dapat dicabut aksesnya dari sini.'
        );

        $user->forceFill(['is_admin' => false])->save();

        $user->roles()->detach();

        app(ActivityLogger::class)->log('dicabut status adminnya', 'user', $user);

        return back()->with('status', $user->display_name.' bukan admin lagi.');
    }

    /**
     * Mencegah admin memblokir dirinya sendiri atau admin lain — jalan
     * paling mudah untuk mengunci diri sendiri dari panel.
     */
    private function guardedUser(int $id): User
    {
        $user = User::findOrFail($id);

        abort_if($user->id === Auth::id(), 403, 'Anda tidak bisa mengubah akun Anda sendiri di sini.');
        abort_if($user->is_admin, 403, 'Akun admin tidak dapat diubah dari halaman ini.');

        return $user;
    }
}
