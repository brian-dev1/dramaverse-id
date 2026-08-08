<?php

namespace App\Http\Controllers\Admin;

use App\Models\Drama;
use App\Models\Episode;
use App\Services\Admin\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\Admin\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /*
    |--------------------------------------------------------------------------
    | Daftar: dikelompokkan per drama
    |--------------------------------------------------------------------------
    |
    | Satu daftar berisi semua episode dari semua drama cepat sekali menjadi
    | ribuan baris yang tak terbaca. Maka pintu masuk menu Episode adalah
    | daftar DRAMA; episodenya baru muncul setelah salah satu dipilih.
    |
    | Tabel episode yang lama tidak dibuang — ia dipakai persis seperti dulu
    | begitu ?drama_id= ada di URL, sehingga seluruh redirect lama
    | (admin.episode.index dengan drama_id) tetap mendarat di tempat benar.
    */
    public function index(Request $request): View
    {
        if ($request->filled('drama_id')) {
            return parent::index($request);
        }

        $keyword = trim((string) $request->get('q'));

        $dramas = Drama::query()
            ->with('country:id,name')
            ->when($keyword !== '', fn (Builder $q) => $q->where('title', 'like', "%{$keyword}%"))
            ->withCount([
                'episodes',
                'episodes as episodes_vip_count' => fn ($q) => $q->where('is_vip', true),
                'episodes as episodes_published_count' => fn ($q) => $q->where('status', 'published'),
            ])
            ->withMax('episodes', 'episode_number')
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();

        return view('web.pages.admin.episode-groups', [
            'dramas'  => $dramas,
            'title'   => 'Episode',
            'keyword' => $keyword,
        ]);
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

        // duration NOT NULL + default 0: field kosong mengirim null dan
        // menabrak constraint. Buang key-nya agar default/nilai lama dipakai.
        if (array_key_exists('duration', $data) && $data['duration'] === null) {
            unset($data['duration']);
        }

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

    /*
    |--------------------------------------------------------------------------
    | Tambah banyak episode sekaligus
    |--------------------------------------------------------------------------
    */

    public function batchForm(Request $request): View
    {
        return view('web.pages.admin.episode-batch', [
            'dramas' => Drama::orderBy('title')->get(['id', 'title']),
            'dramaId' => $request->integer('drama_id') ?: null,
            'nextNumbers' => Episode::query()
                ->selectRaw('drama_id, MAX(episode_number) + 1 AS next')
                ->groupBy('drama_id')
                ->pluck('next', 'drama_id')
                ->all(),
        ]);
    }

    /**
     * Membuat beberapa RENTANG episode sekaligus.
     *
     * Satu drama biasanya punya pola akses bertingkat: episode 1 gratis
     * sebagai umpan, sisanya VIP. Memasukkannya satu per satu — atau bahkan
     * satu rentang per submit — berarti admin harus bolak-balik ke form yang
     * sama. Di sini setiap baris rentang berdiri sendiri: nomor awal, nomor
     * akhir, akses, dan status masing-masing.
     */
    public function batchStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'drama_id'          => ['required', 'integer', 'exists:dramas,id'],
            'ranges'            => ['required', 'array', 'min:1', 'max:20'],
            'ranges.*.from'     => ['required', 'integer', 'min:1', 'max:9999'],
            'ranges.*.to'       => ['required', 'integer', 'min:1', 'max:9999'],
            'ranges.*.is_vip'   => ['nullable', 'boolean'],
            'ranges.*.status'   => ['required', 'in:draft,published'],
            'duration'          => ['nullable', 'integer', 'min:0'],
            'url_pattern'       => ['nullable', 'string', 'max:500'],
        ], [
            'ranges.required' => 'Isi minimal satu rentang episode.',
        ]);

        // Rentang terbalik dan rentang raksasa ditolak di sini, bukan
        // dibiarkan meledak sebagai 20.000 insert di dalam transaksi.
        $total = 0;

        foreach ($data['ranges'] as $i => $range) {
            if ($range['to'] < $range['from']) {
                return back()->withInput()->withErrors([
                    "ranges.{$i}.to" => 'Nomor akhir tidak boleh lebih kecil dari nomor awal.',
                ]);
            }

            $total += $range['to'] - $range['from'] + 1;
        }

        if ($total > 300) {
            return back()->withInput()->withErrors([
                'ranges' => "Total {$total} episode terlalu banyak sekali jalan. Maksimal 300.",
            ]);
        }

        $drama = Drama::findOrFail($data['drama_id']);

        // Nomor yang sudah dipakai dilewati, bukan ditimpa.
        $existing = Episode::where('drama_id', $drama->id)
            ->pluck('episode_number')
            ->flip();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $drama, $existing, &$created, &$skipped) {
            foreach ($data['ranges'] as $range) {
                $vip    = (bool) ($range['is_vip'] ?? false);
                $status = $range['status'];

                for ($number = $range['from']; $number <= $range['to']; $number++) {
                    // Bentrok bisa datang dari database maupun dari rentang
                    // lain di form yang sama, jadi daftarnya ikut tumbuh.
                    if ($existing->has($number)) {
                        $skipped++;
                        continue;
                    }

                    Episode::create([
                        'drama_id'       => $drama->id,
                        'episode_number' => $number,
                        'title'          => 'Episode '.$number,
                        'slug'           => \Illuminate\Support\Str::slug($drama->slug.'-episode-'.$number),
                        'video_url'      => $this->expandPattern($data['url_pattern'] ?? null, $number),
                        'duration'       => $data['duration'] ?? 0,
                        'is_vip'         => $vip,
                        'status'         => $status,
                        'published_at'   => $status === 'published' ? now() : null,
                    ]);

                    $existing->put($number, true);
                    $created++;
                }
            }
        });

        $this->syncEpisodeCount($drama->id);

        app(ActivityLogger::class)->log('dibuat massal', 'episode', $drama, [
            'dibuat'    => $created,
            'dilewati'  => $skipped,
        ]);

        $message = "{$created} episode dibuat untuk {$drama->title}.";

        if ($skipped > 0) {
            $message .= " {$skipped} nomor dilewati karena sudah ada.";
        }

        return redirect()
            ->route('admin.episode.index', ['drama_id' => $drama->id])
            ->with('status', $message);
    }

    /**
     * Mengubah pola URL menjadi URL episode.
     * Penanda {n} diganti nomor episode, {nn} diganti nomor dua digit.
     */
    private function expandPattern(?string $pattern, int $number): ?string
    {
        if (blank($pattern)) {
            return null;
        }

        return str_replace(
            ['{n}', '{nn}', '{nnn}'],
            [$number, str_pad((string) $number, 2, '0', STR_PAD_LEFT), str_pad((string) $number, 3, '0', STR_PAD_LEFT)],
            $pattern
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Urutan episode
    |--------------------------------------------------------------------------
    */

    /** Menyimpan urutan baru dari seret-lepas. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'drama_id' => ['required', 'integer', 'exists:dramas,id'],
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:episodes,id'],
        ]);

        DB::transaction(function () use ($data) {
            // Dua tahap: nomor dinaikkan jauh dulu supaya tidak melanggar
            // batasan unik (drama_id, episode_number) saat bertukar posisi.
            Episode::where('drama_id', $data['drama_id'])
                ->whereIn('id', $data['ids'])
                ->increment('episode_number', 10000);

            foreach (array_values($data['ids']) as $index => $id) {
                Episode::whereKey($id)
                    ->where('drama_id', $data['drama_id'])
                    ->update(['episode_number' => $index + 1]);
            }
        });

        app(ActivityLogger::class)->log('diurutkan', 'episode', null, [
            'drama_id' => $data['drama_id'],
            'jumlah'   => count($data['ids']),
        ]);

        return response()->json(['ok' => true]);
    }
}
