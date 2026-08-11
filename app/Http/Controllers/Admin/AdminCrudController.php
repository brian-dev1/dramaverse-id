<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ActivityLogger;
use App\Support\AdminReturnUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Basis seluruh CRUD panel admin.
 *
 * Menyediakan daftar (dengan pencarian, filter, urutan, pagination),
 * form tambah/ubah, simpan, hapus, pulihkan, dan aksi massal. Setiap
 * turunan cukup mendeklarasikan konfigurasinya — tidak ada logika yang
 * ditulis ulang per entitas.
 */
abstract class AdminCrudController extends Controller
{
    protected int $perPage = 20;

    /*
    |--------------------------------------------------------------------------
    | Wajib diisi turunan
    |--------------------------------------------------------------------------
    */

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Kunci rute, mis. "drama" untuk admin.drama.index. */
    abstract protected function routeKey(): string;

    /** Label modul untuk judul dan catatan aktivitas. */
    abstract protected function label(): string;

    /** Kolom tabel: ['Judul' => 'title', ...] */
    abstract protected function columns(): array;

    /** Aturan validasi. $model diisi saat pembaruan. */
    abstract protected function rules(Request $request, ?Model $model = null): array;

    /*
    |--------------------------------------------------------------------------
    | Dapat ditimpa turunan
    |--------------------------------------------------------------------------
    */

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function relations(): array
    {
        return [];
    }

    /** Relasi yang dihitung jumlahnya, mis. ['dramas'] -> dramas_count. */
    protected function withCount(): array
    {
        return [];
    }

    /** Kolom yang boleh dipakai mengurutkan. */
    protected function sortable(): array
    {
        return array_values($this->columns());
    }

    /** Filter tambahan: ['status' => ['label' => 'Status', 'options' => [...]]] */
    protected function filters(): array
    {
        return [];
    }

    /**
     * Urutan bawaan, dipakai bila pengguna belum memilih kolom urutan.
     *
     * Terbaru-dulu tepat untuk hampir semua modul, tapi tidak semuanya:
     * daftar storage provider lebih berguna diurutkan menurut prioritas,
     * karena itulah urutan yang benar-benar dipakai sistem. Disediakan
     * sebagai hook agar turunan tidak perlu menimpa index() seluruhnya
     * hanya untuk mengubah satu baris.
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->latest();
    }

    /** Data tambahan untuk form (daftar genre, negara, dan sebagainya). */
    protected function formData(?Model $model = null): array
    {
        return [];
    }

    /** Menyiapkan data sebelum disimpan. */
    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        return $data;
    }

    /** Dijalankan setelah record tersimpan — mis. sinkronisasi relasi. */
    protected function afterSave(Request $request, Model $model, bool $created): void
    {
        //
    }

    /** Apakah entitas memakai soft delete. */
    protected function softDeletes(): bool
    {
        return in_array(
            'Illuminate\Database\Eloquent\SoftDeletes',
            class_uses_recursive($this->model()),
            true
        );
    }

    /** Aksi massal yang tersedia: kunci => label. */
    protected function bulkActions(): array
    {
        return ['delete' => 'Hapus terpilih'];
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $model = $this->model();

        $query = $model::query()
            ->with($this->relations())
            ->withCount($this->withCount());

        if ($this->softDeletes() && $request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        // --- Pencarian ---
        if ($keyword = trim((string) $request->get('q'))) {
            $columns = $this->searchable();

            $query->where(function (Builder $q) use ($columns, $keyword) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$keyword}%");
                }
            });
        }

        // --- Filter ---
        foreach (array_keys($this->filters()) as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->get($field));
            }
        }

        // --- Urutan ---
        $sort = (string) $request->get('sort', '');
        $dir  = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        if ($sort !== '' && in_array($sort, $this->sortable(), true) && ! str_contains($sort, '.')) {
            $query->orderBy($sort, $dir);
        } else {
            $this->applyDefaultSort($query);
        }

        $records = $query->paginate($this->perPage)->withQueryString();

        return view('web.pages.admin.crud.index', [
            'records'      => $records,
            'title'        => $this->label(),
            'routeKey'     => $this->routeKey(),
            'columns'      => $this->columns(),
            'filters'      => $this->filters(),
            'sortable'     => $this->sortable(),
            'bulkActions'  => $this->bulkActions(),
            'softDeletes'  => $this->softDeletes(),
            'keyword'      => $keyword ?? '',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    /**
     * Record kosong untuk form tambah.
     *
     * Disediakan sebagai hook supaya turunan dapat mengisi nilai awal dari
     * konteks tempat admin menekan Tambah — mis. drama yang sedang disaring —
     * tanpa perlu menimpa create() seluruhnya.
     */
    protected function newRecord(Request $request): Model
    {
        return new ($this->model());
    }

    public function create(): View
    {
        $model = $this->newRecord(request());

        return view($this->formView(), array_merge([
            'record'   => $model,
            'title'    => 'Tambah '.$this->label(),
            'routeKey' => $this->routeKey(),
            'mode'     => 'create',
        ], $this->formData()));
    }

    public function edit(int $id): View
    {
        $record = $this->findOrFail($id);

        return view($this->formView(), array_merge([
            'record'   => $record,
            'title'    => 'Ubah '.$this->label(),
            'routeKey' => $this->routeKey(),
            'mode'     => 'edit',
        ], $this->formData($record)));
    }

    protected function formView(): string
    {
        return 'web.pages.admin.crud.'.$this->routeKey().'-form';
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules($request));

        try {
            $record = DB::transaction(function () use ($request, $data) {
                $model = $this->model();
                $record = $model::create($this->prepare($request, $data));

                $this->afterSave($request, $record, created: true);

                return $record;
            });
        } catch (Throwable $e) {
            return $this->saveFailed($request, $e, 'menambahkan');
        }

        app(ActivityLogger::class)->log('dibuat', $this->routeKey(), $record);

        return redirect()
            ->to($this->redirectAfterSave($request))
            ->with('status', $this->label().' berhasil ditambahkan.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $record = $this->findOrFail($id);

        $data = $request->validate($this->rules($request, $record));

        try {
            DB::transaction(function () use ($request, $data, $record) {
                $record->update($this->prepare($request, $data, $record));

                $this->afterSave($request, $record, created: false);
            });
        } catch (Throwable $e) {
            return $this->saveFailed($request, $e, 'memperbarui');
        }

        app(ActivityLogger::class)->log('diubah', $this->routeKey(), $record);

        return redirect()
            ->to($this->redirectAfterSave($request))
            ->with('status', $this->label().' berhasil diperbarui.');
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus & pulihkan
    |--------------------------------------------------------------------------
    */

    public function destroy(int $id): RedirectResponse
    {
        $record = $this->findOrFail($id);

        $record->delete();

        app(ActivityLogger::class)->log('dihapus', $this->routeKey(), $record);

        return back()->with('status', $this->label().' berhasil dihapus.');
    }

    public function restore(int $id): RedirectResponse
    {
        abort_unless($this->softDeletes(), 404);

        $model = $this->model();
        $record = $model::onlyTrashed()->findOrFail($id);

        $record->restore();

        app(ActivityLogger::class)->log('dipulihkan', $this->routeKey(), $record);

        return back()->with('status', $this->label().' berhasil dipulihkan.');
    }

    /*
    |--------------------------------------------------------------------------
    | Aksi massal
    |--------------------------------------------------------------------------
    */

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        abort_unless(array_key_exists($data['action'], $this->bulkActions()), 422);

        $model = $this->model();
        $query = $model::query()->whereIn('id', $data['ids']);

        $count = $this->applyBulk($data['action'], $query);

        app(ActivityLogger::class)->log(
            'massal: '.$data['action'],
            $this->routeKey(),
            null,
            ['jumlah' => $count]
        );

        return back()->with('status', "{$count} {$this->label()} diproses.");
    }

    /**
     * Menjalankan satu aksi massal. Turunan menimpa untuk aksi khusus.
     *
     * `activate` dan `deactivate` ada di sini sejak Phase 12: tiga turunan
     * (Banner, Country, Genre) menuliskan implementasi yang sama persis.
     * Modul tanpa kolom `is_active` tidak terpengaruh — aksi itu hanya
     * dijalankan bila terdaftar di `bulkActions()` masing-masing.
     */
    protected function applyBulk(string $action, Builder $query): int
    {
        return match ($action) {
            'activate'   => $query->update(['is_active' => true]),
            'deactivate' => $query->update(['is_active' => false]),
            'delete'     => $query->delete(),
            default      => 0,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Ke mana admin dikembalikan setelah simpan.
     *
     * Daftar menitipkan alamatnya sendiri lewat `?kembali=`, jadi simpan dari
     * halaman 2 (atau dari hasil pencarian, atau dari daftar drama induk)
     * mendarat kembali di sana — bukan di halaman 1 daftar modul ini.
     */
    protected function redirectAfterSave(Request $request): string
    {
        return AdminReturnUrl::current($request)
            ?? route('admin.'.$this->routeKey().'.index');
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->model();

        $query = $this->softDeletes()
            ? $model::withTrashed()
            : $model::query();

        return $query->findOrFail($id);
    }

    protected function saveFailed(Request $request, Throwable $e, string $action): RedirectResponse
    {
        Log::error('admin.crud.save_failed', [
            'module' => $this->routeKey(),
            'action' => $action,
            'error' => $e->getMessage(),
        ]);

        return back()
            ->withInput($request->except(['poster_file', 'cover_file', 'thumbnail_file', 'image_file']))
            ->with('error', 'Gagal '.$action.' '.$this->label().'. Cek migration/database dan storage, detailnya sudah masuk log.');
    }
}
