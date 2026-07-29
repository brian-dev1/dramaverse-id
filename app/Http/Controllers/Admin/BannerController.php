<?php

namespace App\Http\Controllers\Admin;

use App\Models\Banner;
use App\Models\Drama;
use App\Services\Admin\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BannerController extends AdminCrudController
{
    public function __construct(
        protected MediaService $media
    ) {
    }

    protected function model(): string
    {
        return Banner::class;
    }

    protected function routeKey(): string
    {
        return 'banner';
    }

    protected function label(): string
    {
        return 'Banner';
    }

    protected function columns(): array
    {
        return [
            'Gambar' => 'image',
            'Judul'  => 'title',
            'Posisi' => 'position',
            'Mulai'  => 'start_at',
            'Sampai' => 'end_at',
            'Urutan' => 'sort_order',
            'Aktif'  => 'is_active',
        ];
    }

    protected function sortable(): array
    {
        return ['title', 'position', 'sort_order', 'start_at'];
    }

    protected function searchable(): array
    {
        return ['title', 'subtitle'];
    }

    protected function filters(): array
    {
        return [
            'position' => [
                'label'   => 'Posisi',
                'options' => $this->positions(),
            ],
            'is_active' => [
                'label'   => 'Status',
                'options' => [1 => 'Aktif', 0 => 'Nonaktif'],
            ],
        ];
    }

    protected function formData(?Model $model = null): array
    {
        return [
            'positions' => $this->positions(),
            'dramas'    => Drama::orderBy('title')->get(['id', 'title', 'slug']),
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'subtitle'    => ['nullable', 'string', 'max:500'],
            'image_file'  => MediaService::rules(required: ! $model?->image),
            'link'        => ['nullable', 'string', 'max:255'],
            'button_text' => ['nullable', 'string', 'max:50'],
            'position'    => ['required', Rule::in(array_keys($this->positions()))],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'   => ['boolean'],
            'start_at'    => ['nullable', 'date'],
            'end_at'      => ['nullable', 'date', 'after:start_at'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('image_file')) {
            $data['image'] = $this->media->store($request->file('image_file'), 'banner', $model?->image);
        }

        unset($data['image_file']);

        return $data;
    }

    protected function bulkActions(): array
    {
        return [
            'activate'   => 'Aktifkan',
            'deactivate' => 'Nonaktifkan',
            'delete'     => 'Hapus terpilih',
        ];
    }

    protected function applyBulk(string $action, Builder $query): int
    {
        return match ($action) {
            'activate'   => $query->update(['is_active' => true]),
            'deactivate' => $query->update(['is_active' => false]),
            'delete'     => $query->delete(),
            default      => 0,
        };
    }

    /** @return array<string, string> */
    private function positions(): array
    {
        return [
            'hero'       => 'Hero beranda',
            'carousel'   => 'Carousel',
            'promo'      => 'Promo',
            'membership' => 'Membership',
        ];
    }
}
