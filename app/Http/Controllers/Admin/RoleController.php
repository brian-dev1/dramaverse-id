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
            'permissionGroups' => Permission::orderBy('module')->orderBy('name')->get()->groupBy('module'),
            'selectedPermissions' => $model?->exists ? $model->permissions->pluck('id')->all() : [],
            'admins' => User::where('is_admin', true)->orderBy('name')->get(['id', 'name', 'email']),
            'selectedUsers' => $model?->exists ? $model->users->pluck('id')->all() : [],
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'slug'          => ['nullable', 'string', 'max:50', 'alpha_dash',
                                Rule::unique('roles', 'slug')->ignore($model?->getKey())],
            'description'   => ['nullable', 'string', 'max:500'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
            'users'         => ['nullable', 'array'],
            'users.*'       => ['integer', 'exists:users,id'],
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
        // Super admin selalu memegang seluruh izin — tidak bisa dikurangi.
        $permissions = $role->isSuperAdmin()
            ? Permission::pluck('id')->all()
            : $request->input('permissions', []);

        $role->permissions()->sync($permissions);
        $role->users()->sync($request->input('users', []));
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
