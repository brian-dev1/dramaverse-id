<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Models\VideoInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VideoInboxController extends Controller
{
    public function index(): View
    {
        $videos = VideoInbox::query()
            ->with([
                'provider:id,name,slug,bucket',
                'episode:id,drama_id,episode_number,title',
                'episode.drama:id,title,slug',
            ])
            ->latest('uploaded_at')
            ->paginate(20);

        $dramas = Drama::query()
            ->select('id', 'title')
            ->orderBy('title')
            ->get();

        return view('web.pages.admin.video-inbox', compact(
            'videos',
            'dramas'
        ));
    }

    /**
     * Memasang banyak video sekaligus ke episode-episodenya.
     *
     * Satu permintaan membawa banyak pasangan video→episode. Yang tidak layak
     * DILEWATI, bukan membatalkan seluruh permintaan: admin yang mencentang
     * dua belas video tidak boleh kehilangan sebelas pemasangan yang benar
     * hanya karena satu videonya bermasalah. Alasan tiap yang dilewati
     * dikembalikan supaya bisa ditindaklanjuti.
     *
     * Yang TETAP membatalkan seluruhnya hanyalah kesalahan bentuk permintaan —
     * misalnya dua video diarahkan ke episode yang sama. Itu bukan kondisi
     * data, melainkan salah isi form, dan menjalankannya sebagian justru
     * menyisakan keadaan yang sulit ditebak.
     */
    public function assign(Request $request): RedirectResponse
    {
        /*
        | Baris yang tidak dicentang tetap ikut terkirim bila JavaScript gagal
        | menonaktifkan input-nya. Baris tanpa `video_id` berarti "tidak
        | dicentang" dan dibuang di sini — bukan dibiarkan menjadi error
        | validasi yang membingungkan.
        |
        | Baris yang PUNYA video_id tetapi episodenya kosong sengaja
        | dibiarkan lewat, supaya validasi di bawah memberi tahu admin bahwa
        | ada video yang dicentang tanpa episode.
        */
        $request->merge([
            'pairs' => collect($request->input('pairs', []))
                ->filter(fn ($pair) => is_array($pair) && filled($pair['video_id'] ?? null))
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'drama_id'              => ['required', 'integer', 'exists:dramas,id'],
            'pairs'                 => ['required', 'array', 'min:1'],
            'pairs.*.video_id'      => ['required', 'integer', 'distinct'],
            'pairs.*.episode_id'    => ['required', 'integer'],
        ], [
            'pairs.required'             => 'Centang minimal satu video dan pilih episodenya.',
            'pairs.*.episode_id.required' => 'Ada video yang dicentang tetapi belum dipilih episodenya.',
        ]);

        $pairs = collect($data['pairs']);

        // Dua video ke satu episode: yang belakangan akan menimpa yang duluan
        // tanpa pernah terlihat. Ditolak sebelum apa pun tersimpan.
        $episodeIds = $pairs->pluck('episode_id');

        if ($episodeIds->count() !== $episodeIds->unique()->count()) {
            return back()
                ->withInput()
                ->with(
                    'error',
                    'Ada dua video atau lebih yang diarahkan ke episode yang sama. Perbaiki dulu pilihannya.'
                );
        }

        $videos = VideoInbox::query()
            ->with('provider')
            ->whereIn('id', $pairs->pluck('video_id'))
            ->get()
            ->keyBy('id');

        // Episode dibatasi pada drama yang dipilih. Tanpa batasan ini, id
        // episode yang dikirim tangan bisa menunjuk drama mana pun.
        $episodes = Episode::query()
            ->with('video:id,episode_id')
            ->where('drama_id', $data['drama_id'])
            ->whereIn('id', $episodeIds)
            ->get()
            ->keyBy('id');

        $terpasang = 0;
        $dilewati  = [];

        foreach ($pairs as $pair) {
            $video   = $videos->get($pair['video_id']);
            $episode = $episodes->get($pair['episode_id']);

            $nama = $video?->original_filename ?? 'Video #'.$pair['video_id'];

            if ($video === null) {
                $dilewati[] = $nama.': video tidak ditemukan.';
                continue;
            }

            if (! $video->isAvailable()) {
                $dilewati[] = $nama.': sudah terpasang atau tidak lagi tersedia.';
                continue;
            }

            if (blank($video->checksum)) {
                $dilewati[] = $nama.': belum punya checksum SHA-256.';
                continue;
            }

            if ($video->provider === null) {
                $dilewati[] = $nama.': storage provider tidak ditemukan.';
                continue;
            }

            if ($episode === null) {
                $dilewati[] = $nama.': episode tujuan tidak ada pada drama ini.';
                continue;
            }

            // Episode yang sudah punya video tidak ditimpa. Ini keputusan
            // sadar: menimpa berarti kehilangan berkas lama tanpa jalan
            // kembali, dan salah centang jauh lebih mudah terjadi daripada
            // niat mengganti video.
            if ($episode->video !== null) {
                $dilewati[] = $nama.': Episode '
                    .str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT)
                    .' sudah punya video.';
                continue;
            }

            $this->pasang($video, $episode);

            $terpasang++;
        }

        return back()->with([
            'success'   => $this->ringkasan($terpasang, count($dilewati)),
            'dilewati'  => $dilewati,
        ]);
    }

    /**
     * Memasangkan satu object storage yang sudah ada ke satu episode.
     *
     * Tidak ada unduh maupun unggah ulang di sini — berkasnya sudah berada di
     * storage provider sejak worker Telegram menaruhnya. Yang dibuat hanya
     * catatan yang menunjuk ke object itu.
     */
    private function pasang(VideoInbox $video, Episode $episode): void
    {
        $provider = $video->provider;

        $objectKey = ltrim($video->object_key, '/');

        $directory = pathinfo($objectKey, PATHINFO_DIRNAME);

        if ($directory === '.') {
            $directory = null;
        }

        $storedFilename = pathinfo($objectKey, PATHINFO_BASENAME);

        $extension = pathinfo($video->original_filename, PATHINFO_EXTENSION);

        DB::transaction(function () use (
            $video,
            $episode,
            $provider,
            $objectKey,
            $directory,
            $storedFilename,
            $extension
        ) {
            EpisodeVideo::updateOrCreate(
                [
                    'episode_id' => $episode->id,
                ],
                [
                    'storage_provider_id' => $provider->id,
                    'uploaded_by' => Auth::id(),

                    'disk' => $provider->slug,
                    'bucket' => $provider->bucket,

                    'object_key' => $objectKey,
                    'directory' => $directory,

                    'original_filename' => $video->original_filename,
                    'stored_filename' => $storedFilename,
                    'extension' => $extension ?: null,

                    'mime_type' => $video->mime_type ?: 'video/mp4',
                    'size' => $video->size,
                    'checksum' => $video->checksum,

                    'public_url' => $video->public_url,
                    'uploaded_at' => $video->uploaded_at ?? now(),
                ]
            );

            $video->update([
                'status' => 'assigned',
                'episode_id' => $episode->id,
                'assigned_at' => now(),
            ]);
        });
    }

    /** Kalimat hasil yang menyebut angka apa adanya, termasuk saat nol. */
    private function ringkasan(int $terpasang, int $dilewati): string
    {
        if ($terpasang === 0) {
            return 'Tidak ada video yang terpasang. '.$dilewati.' dilewati.';
        }

        $pesan = $terpasang.' video terpasang.';

        if ($dilewati > 0) {
            $pesan .= ' '.$dilewati.' dilewati.';
        }

        return $pesan;
    }
}
