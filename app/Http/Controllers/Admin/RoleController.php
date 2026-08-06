<?php

namespace App\Http\Controllers\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends AdminCrudController
{
    protected function model(): string
    {
        return Role::class;
    }

    protected function routeKey(): string
    {
        return 'role';
    }

    protected function label(): string
    {
        return 'Peran';
    }

    protected function columns(): array
    {
        return [
            'Nama'       => 'name',
            'Slug'       => 'slug',
            'Keterangan' => 'description',
            'Izin'       => 'permissions_count',
            'Pengguna'   => 'users_count',
        ];
    }

    protected function sortable(): array
    {
        return ['name', 'slug'];
    }

    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    protected function withCount(): array
    {
        return ['permissions', 'users'];
    }

    protected function bulkActions(): array
    {
        return [];
    }

    protected function formData(?Model $model = null): array
    {
        return [
            // Izin dikelompokkan per modul agar form mudah dibaca.
            'permissionGroups' => Permission::orderBy('module')
                ->orderBy('name')
                ->get()
                ->groupBy('module'),

            'selectedPermissions' => $model?->exists
                ? $model->permissions->pluck('id')->all()
                : [],

            /*
             * Root Owner tidak dikelola melalui Role Management.
             *
             * Hak Root berasal dari users.is_root dan tidak bergantung
             * pada pivot role_user. Karena itu Root tidak ditampilkan
             * sebagai pilihan assignment role.
             */
            'admins' => User::query()
                ->where('is_admin', true)
                ->where('is_root', false)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),

            'selectedUsers' => $model?->exists
                ? $model->users()
                    ->where('users.is_root', false)
                    ->pluck('users.id')
                    ->all()
                : [],
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'slug'          => [
                'nullable',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('roles', 'slug')->ignore($model?->getKey()),
            ],
            'description'   => ['nullable', 'string', 'max:500'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
            'users'         => ['nullable', 'array'],
            'users.*'       => [
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('is_admin', true)
                        ->where('is_root', false)
                ),
            ],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        $data['slug'] = filled($data['slug'] ?? null)
            ? Str::slug($data['slug'])
            : Str::slug($data['name']);

        unset($data['permissions'], $data['users']);

        return $data;
    }

    protected function afterSave(Request $request, Model $role, bool $created): void
    {
        /*
         * Super Admin selalu memegang seluruh izin dan tidak bisa dikurangi
         * melalui form.
         */
        $permissions = $role->isSuperAdmin()
            ? Permission::pluck('id')->all()
            : $request->input('permissions', []);

        $role->permissions()->sync($permissions);

        /*
         * Jangan gunakan sync() langsung terhadap seluruh users karena
         * operasi itu dapat melepaskan Root Owner dari pivot yang sudah ada.
         *
         * Root tidak dikelola dari halaman ini. Kita hanya menyinkronkan
         * membership role untuk admin non-root.
         */
        $selectedUsers = User::query()
            ->where('is_admin', true)
            ->where('is_root', false)
            ->whereIn('id', $request->input('users', []))
            ->pluck('id')
            ->all();

        $currentRootUsers = $role->users()
            ->where('users.is_root', true)
            ->pluck('users.id')
            ->all();

        $role->users()->sync(
            array_values(array_unique([
                ...$currentRootUsers,
                ...$selectedUsers,
            ]))
        );
    }

    /** Peran bawaan super admin tidak boleh dihapus. */
    public function destroy(int $id): RedirectResponse
    {
        $role = $this->findOrFail($id);

        if ($role->isSuperAdmin()) {
            return back()->withErrors([
                'role' => 'Peran Super Admin tidak dapat dihapus — tanpa peran ini panel bisa terkunci.',
            ]);
        }

        return parent::destroy($id);
    }
}