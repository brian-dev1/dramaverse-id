<?php

namespace App\Http\Controllers\Admin;

use App\Models\Drama;
use App\Models\Episode;
use App\Services\Admin\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EpisodeController extends AdminCrudController
{
    public function __construct(
        protected MediaService $media
    ) {
    }

    protected function model(): string
    {
        return Episode::class;
    }

    protected function routeKey(): string
    {
        return 'episode';
    }

    protected function label(): string
    {
        return 'Episode';
    }

    protected function columns(): array
    {
        return [
            'Drama'   => 'drama.title',
            'Nomor'   => 'episode_number',
            'Judul'   => 'title',
            'Durasi'  => 'duration',
            'Akses'   => 'is_vip',
            'Status'  => 'status',
            'Tayang'  => 'air_date',
        ];
    }

    protected function sortable(): array
    {
        return ['episode_number', 'title', 'duration', 'views', 'air_date'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function relations(): array
    {
        return ['drama:id,title'];
    }

    protected function filters(): array
    {
        return [
            'drama_id' => [
                'label'   => 'Drama',
                'options' => Drama::orderBy('title')->pluck('title', 'id')->all(),
            ],
            'status' => [
                'label'   => 'Status',
                'options' => ['draft' => 'Draf', 'published' => 'Terbit'],
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
            'dramas'   => Drama::orderBy('title')->get(['id', 'title']),
            'statuses' => ['draft' => 'Draf', 'published' => 'Terbit'],
            // Peta drama_id => nomor episode berikutnya, dipakai form untuk
            // mengisi otomatis saat drama dipilih.
            'nextNumbers' => Episode::query()
                ->selectRaw('drama_id, MAX(episode_number) + 1 AS next')
                ->groupBy('drama_id')
                ->pluck('next', 'drama_id')
                ->all(),
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        $dramaId = $request->integer('drama_id');

        return [
            'drama_id'       => ['required', 'integer', 'exists:dramas,id'],
            'episode_number' => [
                'required', 'integer', 'min:1', 'max:9999',
                Rule::unique('episodes', 'episode_number')
                    ->where(fn ($q) => $q->where('drama_id', $dramaId))
                    ->ignore($model?->getKey()),
            ],
            'title'          => ['nullable', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:2000'],

            'video_url'      => ['nullable', 'url', 'max:500'],
            'embed_url'      => ['nullable', 'url', 'max:500'],
            'thumbnail_file' => MediaService::rules(),

            'duration'       => ['nullable', 'integer', 'min:0', 'max:86400'],
            'is_vip'         => ['boolean'],

            'air_date'       => ['nullable', 'date'],
            'status'         => ['required', Rule::in(['draft', 'published'])],
            'published_at'   => ['nullable', 'date'],
            'expired_at'     => ['nullable', 'date', 'after:published_at'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        $data['is_vip'] = $request->boolean('is_vip');

        $data['slug'] = \Illuminate\Support\Str::slug(
            ($data['title'] ?? 'episode').'-'.$data['episode_number'].'-'.$data['drama_id']
        );

        if ($request->hasFile('thumbnail_file')) {
            $data['thumbnail'] = $this->media->store(
                $request->file('thumbnail_file'),
                'episode/thumbnail',
                $model?->thumbnail
            );
        }

        // Terbit tanpa tanggal eksplisit dianggap terbit sekarang.
        if ($data['status'] === 'published' && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        unset($data['thumbnail_file']);

        return $data;
    }

    protected function afterSave(Request $request, Model $episode, bool $created): void
    {
        // Jaga agar jumlah episode pada drama selalu akurat.
        $this->syncEpisodeCount($episode->drama_id);
    }

    public function destroy(int $id): RedirectResponse
    {
        $episode = $this->findOrFail($id);
        $dramaId = $episode->drama_id;

        $response = parent::destroy($id);

        $this->syncEpisodeCount($dramaId);

        return $response;
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
        $dramaIds = (clone $query)->pluck('drama_id')->unique();

        $count = match ($action) {
            'publish' => $query->update(['status' => 'published', 'published_at' => now()]),
            'draft'   => $query->update(['status' => 'draft']),
            'vip'     => $query->update(['is_vip' => true]),
            'free'    => $query->update(['is_vip' => false]),
            'delete'  => $query->delete(),
            default   => 0,
        };

        $dramaIds->each(fn ($id) => $this->syncEpisodeCount($id));

        return $count;
    }

    private function syncEpisodeCount(?int $dramaId): void
    {
        if (! $dramaId) {
            return;
        }

        Drama::whereKey($dramaId)->update([
            'total_episode' => Episode::where('drama_id', $dramaId)->count(),
        ]);
    }
}
