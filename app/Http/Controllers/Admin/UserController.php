<?php

namespace App\Http\Controllers\Admin;

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
