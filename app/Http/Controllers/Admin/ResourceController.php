<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Basis daftar data admin.
 *
 * Semua halaman indeks admin memiliki bentuk yang sama — judul, pencarian,
 * tabel, pagination — jadi logikanya dikumpulkan di sini agar tiap controller
 * turunan cukup mendeklarasikan konfigurasinya saja.
 */
abstract class ResourceController extends Controller
{
    protected int $perPage = 20;

    /** Model Eloquent yang dikelola. */
    abstract protected function model(): string;

    /** Judul halaman. */
    abstract protected function title(): string;

    /** Kunci rute, mis. "drama" untuk admin.drama.index. */
    abstract protected function routeKey(): string;

    /** Kolom yang ditampilkan: ['label' => 'kolom']. */
    abstract protected function columns(): array;

    /** Kolom yang bisa dicari. */
    protected function searchable(): array
    {
        return ['name'];
    }

    /** Relasi yang di-eager load. */
    protected function relations(): array
    {
        return [];
    }

    public function index(Request $request): View
    {
        $model = $this->model();

        $query = $model::query()->with($this->relations());

        if ($keyword = trim((string) $request->get('q'))) {
            $columns = $this->searchable();

            $query->where(function (Builder $q) use ($columns, $keyword) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$keyword}%");
                }
            });
        }

        $records = $query->latest()->paginate($this->perPage)->withQueryString();

        return view('web.pages.admin.resource', [
            'records'  => $records,
            'title'    => $this->title(),
            'routeKey' => $this->routeKey(),
            'columns'  => $this->columns(),
            'keyword'  => $keyword ?? '',
        ]);
    }
}
