<?php

namespace App\Http\Controllers\Admin;

use App\Models\Country;
use App\Models\Drama;
use App\Models\Genre;
use App\Repositories\HomeRepository;
use App\Services\Admin\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DramaController extends AdminCrudController
{
    public function __construct(
        protected MediaService $media
    ) {
    }

    protected function model(): string
    {
        return Drama::class;
    }

    protected function routeKey(): string
    {
        return 'drama';
    }

    protected function label(): string
    {
        return 'Drama';
    }

    protected function columns(): array
    {
        return [
            'Poster'  => 'poster',
            'Judul'   => 'title',
            'Negara'  => 'country.name',
            'Part' => 'total_episode',
            'Status'  => 'status',
            'Terbit'  => 'published_at',
        ];
    }

    protected function sortable(): array
    {
        return ['title', 'total_episode', 'views', 'published_at'];
    }

    protected function searchable(): array
    {
        return ['title', 'original_title', 'slug'];
    }

    protected function relations(): array
    {
        return ['country:id,name', 'genres:id,name'];
    }

    protected function filters(): array
    {
        return [
            'status' => [
                'label'   => 'Status',
                'options' => [
                    'ongoing'   => 'Sedang tayang',
                    'completed' => 'Tamat',
                    'upcoming'  => 'Akan tayang',
                ],
            ],
            'country_id' => [
                'label'   => 'Negara',
                'options' => Country::orderBy('name')->pluck('name', 'id')->all(),
            ],
            'is_vip' => [
                'label'   => 'Akses',
                'options' => [0 => 'Gratis', 1 => 'VIP'],
            ],
        ];
    }

    protected function formData(?Model $model = null): array
    {
        return [
            'genres'         => Genre::orderBy('name')->get(['id', 'name']),
            'countries'      => Country::orderBy('name')->get(['id', 'name']),
            'selectedGenres' => $model?->exists ? $model->genres->pluck('id')->all() : [],
            'statuses'       => [
                'ongoing'   => 'Sedang tayang',
                'completed' => 'Tamat',
                'upcoming'  => 'Akan tayang',
            ],
            'gradients'      => ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8'],
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        $id = $model?->getKey();

        return [
            'title'          => ['required', 'string', 'max:255'],
            'slug'           => ['nullable', 'string', 'max:255', Rule::unique('dramas', 'slug')->ignore($id)],
            'original_title' => ['nullable', 'string', 'max:255'],
            'synopsis'       => ['nullable', 'string', 'max:5000'],

            'country_id'     => ['nullable', 'integer', 'exists:countries,id'],
            'genre_ids'      => ['nullable', 'array'],
            'genre_ids.*'    => ['integer', 'exists:genres,id'],

            'poster_file'    => MediaService::rules(),
            'gradient'       => ['nullable', 'string', 'max:8'],
            'trailer_url'    => ['nullable', 'url', 'max:255'],

            'total_episode'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status'         => ['required', Rule::in(['ongoing', 'completed', 'upcoming'])],

            'is_vip'         => ['boolean'],
            'is_featured'    => ['boolean'],
            'is_trending'    => ['boolean'],

            'published_at'   => ['nullable', 'date'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        // Slug dibuat otomatis dari judul bila dikosongkan.
        $data['slug'] = filled($data['slug'] ?? null)
            ? Str::slug($data['slug'])
            : $this->uniqueSlug($data['title'], $model?->getKey());

        // Checkbox tidak terkirim saat tidak dicentang.
        foreach (['is_vip', 'is_featured', 'is_trending'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        if ($request->hasFile('poster_file')) {
            $data['poster'] = $this->media->store($request->file('poster_file'), 'drama/poster', $model?->poster);
        }

        unset($data['poster_file'], $data['genre_ids']);

        /*
        | Kolom berikut NOT NULL dengan default di database. Form yang
        | dikosongkan mengirim null, dan null itu menabrak constraint —
        | admin melihat error meski field-nya memang opsional. Buang saja
        | key-nya saat kosong: baris baru memakai default, baris lama
        | mempertahankan nilai sebelumnya.
        */
        foreach (['total_episode'] as $kolomBerdefault) {
            if (array_key_exists($kolomBerdefault, $data) && $data[$kolomBerdefault] === null) {
                unset($data[$kolomBerdefault]);
            }
        }

        /*
        | Drama BARU tanpa tanggal terbit tidak akan pernah muncul di beranda,
        | karena scopePublished() menyaring published_at yang null. Sebelum ini
        | admin yang mengosongkan field itu — dan itu perilaku default form —
        | mengira dramanya gagal tersimpan, padahal ia tersimpan sebagai draf
        | tanpa ada yang memberi tahu. Maka: buat = terbit sekarang.
        |
        | Saat MENGEDIT, kosong tetap berarti draf. Di sana admin memang sedang
        | melihat nilai lamanya, jadi mengosongkannya adalah tindakan sengaja.
        */
        if ($model === null && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function afterSave(Request $request, Model $drama, bool $created): void
    {
        $drama->genres()->sync($request->input('genre_ids', []));
    }

    protected function bulkActions(): array
    {
        return [
            'publish' => 'Terbitkan',
            'draft'   => 'Jadikan draf',
            'vip'     => 'Tandai VIP',
            'free'    => 'Tandai gratis',
            'delete'  => 'Hapus terpilih',
        ];
    }

    protected function applyBulk(string $action, Builder $query): int
    {
        $terdampak = match ($action) {
            'publish' => $query->update(['published_at' => now()]),
            'draft'   => $query->update(['published_at' => null]),
            'vip'     => $query->update(['is_vip' => true]),
            'free'    => $query->update(['is_vip' => false]),
            'delete'  => $query->delete(),
            default   => 0,
        };

        // Mass update melewati event model, jadi DramaObserver tidak jalan
        // di sini. Cache beranda dibuang manual — setelah update, bukan
        // sebelumnya, supaya permintaan lain tidak sempat mengisinya ulang
        // dengan data lama.
        HomeRepository::flushCatalog();

        return $terdampak;
    }

    /** Menjamin slug unik dengan menambahkan sufiks angka bila perlu. */
    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i    = 1;

        while (
            Drama::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
