<?php

namespace App\Http\Controllers\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AdminAccountController extends AdminCrudController
{
    protected function model(): string
    {
        return User::class;
    }

    protected function routeKey(): string
    {
        return 'admin-account';
    }

    protected function label(): string
    {
        return 'Akun Admin';
    }

    protected function columns(): array
    {
        return [
            'Nama'       => 'name',
            'Email'      => 'email',
            'Root'       => 'is_root',
            'Aktif'      => 'is_active',
            'Diblokir'   => 'is_banned',
            'Login Terakhir' => 'last_login_at',
        ];
    }

    protected function searchable(): array
    {
        return ['name', 'email'];
    }

    protected function sortable(): array
    {
        return ['name', 'email', 'is_active', 'is_banned', 'last_login_at'];
    }

    protected function relations(): array
    {
        return ['roles'];
    }

    protected function filters(): array
    {
        return [
            'is_active' => [
                'label' => 'Status',
                'options' => [
                    '1' => 'Aktif',
                    '0' => 'Nonaktif',
                ],
            ],
        ];
    }

    protected function bulkActions(): array
    {
        /*
         * Akun admin sengaja tidak memiliki bulk delete/disable.
         * Operasi sensitif harus dilakukan satu per satu agar proteksi Root
         * dan proteksi akun sendiri tidak dapat terlewati.
         */
        return [];
    }

    protected function applyDefaultSort(Builder $query): void
    {
        $query
            ->orderByDesc('is_root')
            ->orderBy('name');
    }

    public function index(Request $request): \Illuminate\Contracts\View\View
    {
        /*
         * User biasa tidak boleh muncul pada modul ini.
         */
        $model = $this->model();

        $query = $model::query()
            ->where('is_admin', true)
            ->with($this->relations());

        if ($keyword = trim((string) $request->get('q'))) {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            });
        }

        foreach (array_keys($this->filters()) as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->get($field));
            }
        }

        $sort = (string) $request->get('sort', '');
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        if ($sort !== '' && in_array($sort, $this->sortable(), true)) {
            $query->orderBy($sort, $dir);
        } else {
            $this->applyDefaultSort($query);
        }

        $records = $query->paginate($this->perPage)->withQueryString();

        return view('web.pages.admin.crud.index', [
            'records'     => $records,
            'title'       => $this->label(),
            'routeKey'    => $this->routeKey(),
            'columns'     => $this->columns(),
            'filters'     => $this->filters(),
            'sortable'    => $this->sortable(),
            'bulkActions' => $this->bulkActions(),
            'softDeletes' => false,
            'keyword'     => $keyword ?? '',
        ]);
    }

    protected function formData(?Model $model = null): array
    {
        return [
            'roles' => Role::query()
                ->orderByDesc('slug')
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),

            'selectedRoles' => $model?->exists
                ? $model->roles->pluck('id')->all()
                : [],
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        $updating = $model?->exists === true;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($model?->getKey()),
            ],

            'password' => [
                $updating ? 'nullable' : 'required',
                'string',
                'min:8',
                'confirmed',
            ],

            'roles' => [
                'nullable',
                'array',
            ],

            'roles.*' => [
                'integer',
                'exists:roles,id',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        unset($data['roles'], $data['password_confirmation']);

        /*
         * Password kosong pada edit berarti pertahankan password lama.
         */
        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        /*
         * Semua record yang dibuat dari modul ini selalu admin.
         * Request tidak diberi kesempatan menentukan is_admin/is_root.
         */
        $data['is_admin'] = true;
        $data['is_active'] = $request->boolean('is_active');

        /*
         * Admin yang dibuat melalui panel bukan akun Telegram.
         */
        if ($model === null || ! $model->exists) {
            $data['is_banned'] = false;
        }

        return $data;
    }

    protected function afterSave(Request $request, Model $model, bool $created): void
    {
        /** @var User $model */

        /*
         * Root Owner tidak boleh dikelola melalui assignment role.
         * Hak Root berasal dari is_root.
         */
        if ($model->isRoot()) {
            return;
        }

        $roleIds = Role::query()
            ->whereIn('id', $request->input('roles', []))
            ->pluck('id')
            ->all();

        $model->roles()->sync($roleIds);
    }

    public function edit(int $id): \Illuminate\Contracts\View\View
    {
        /** @var User $record */
        $record = $this->findOrFail($id);

        /*
         * Root boleh dilihat dari daftar, tetapi tidak diedit melalui
         * Admin Account Management. Perubahan kredensial Root nantinya
         * dilakukan melalui mekanisme akun sendiri.
         */
        abort_if(
            $record->isRoot(),
            403,
            'Root Owner tidak dapat diedit melalui Manajemen Admin.'
        );

        return parent::edit($id);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        /** @var User $record */
        $record = $this->findOrFail($id);

        if ($record->isRoot()) {
            return back()->withErrors([
                'admin' => 'Root Owner tidak dapat diubah melalui Manajemen Admin.',
            ]);
        }

        /*
         * Admin tidak boleh menonaktifkan dirinya sendiri.
         */
        if (
            Auth::id() === $record->id
            && ! $request->boolean('is_active')
        ) {
            return back()
                ->withInput()
                ->withErrors([
                    'admin' => 'Anda tidak dapat menonaktifkan akun admin yang sedang digunakan.',
                ]);
        }

        return parent::update($request, $id);
    }

    public function destroy(int $id): RedirectResponse
    {
        /** @var User $record */
        $record = $this->findOrFail($id);

        if ($record->isRoot()) {
            return back()->withErrors([
                'admin' => 'Root Owner tidak dapat dihapus.',
            ]);
        }

        if (Auth::id() === $record->id) {
            return back()->withErrors([
                'admin' => 'Anda tidak dapat menghapus akun admin yang sedang digunakan.',
            ]);
        }

        /*
         * Putuskan pivot terlebih dahulu agar database tidak menyisakan
         * assignment role bila constraint pivot tidak memakai cascade.
         */
        $record->roles()->detach();

        return parent::destroy($id);
    }

    public function restore(int $id): RedirectResponse
    {
        abort(404);
    }

    public function bulk(Request $request): RedirectResponse
    {
        abort(404);
    }
}