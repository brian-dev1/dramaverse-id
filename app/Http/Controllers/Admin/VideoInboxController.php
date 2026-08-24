<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Models\VideoInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VideoInboxController extends Controller
{
    /**
     * Daftar video, BAWAANNYA hanya yang belum terpasang.
     *
     * Dulu seluruh isi inbox ditampilkan, terpasang maupun belum. Akibatnya
     * halaman ini tumbuh selamanya: tiap video yang selesai dipasang tetap
     * duduk di sana beserta dua kotak pilihan yang sudah tidak ada gunanya,
     * dan yang benar-benar perlu dikerjakan makin jauh terdorong ke bawah.
     * Halaman kerja yang isinya sebagian besar pekerjaan yang sudah selesai
     * akan berhenti dibaca, dan yang terlewat justru barisan paling bawah —
     * video terbaru.
     *
     * Yang terpasang tidak dihapus dari pandangan, cuma dipindahkan ke
     * tampilan sendiri: ia tetap catatan berkas mana sudah jadi part mana,
     * dan itu pertanyaan yang muncul justru saat ada yang salah.
     */
    public function index(Request $request): View
    {
        $tampil = $request->query('tampil') === 'terpasang' ? 'terpasang' : 'tersedia';

        $videos = VideoInbox::query()
            ->with([
                'provider:id,name,slug,bucket',
                'episode:id,drama_id,episode_number,title',
                'episode.drama:id,title,slug',
            ])
            ->when($tampil === 'tersedia', fn ($q) => $this->belumTerpasang($q))
            ->when($tampil === 'terpasang', fn ($q) => $this->sudahTerpasang($q))
            ->latest('uploaded_at')
            ->paginate(20)

            // Tanpa ini, menekan halaman 2 mengembalikan tampilan ke bawaan
            // dan admin yang sedang memeriksa yang terpasang tiba-tiba
            // melihat daftar yang lain.
            ->withQueryString();

        /*
        | Hanya drama yang MASIH punya part kosong.
        |
        | Daftar ini dulu memuat seluruh katalog, termasuk drama yang setiap
        | partnya sudah bervideo. Memilihnya tidak pernah menghasilkan apa
        | pun — daftar partnya keluar dengan semua baris bertanda "sudah ada
        | video — akan dilewati" — jadi yang tersisa cuma puluhan judul yang
        | harus dilewati mata sebelum sampai ke judul yang benar-benar sedang
        | dikerjakan. Katalog yang bertambah membuat daftar ini makin panjang
        | justru saat pekerjaannya makin sedikit.
        |
        | `whereHas` dengan `whereDoesntHave`, bukan menghitung selisih di
        | PHP: yang dibutuhkan cuma "ada tidaknya satu part kosong", dan
        | pertanyaan itu dijawab basis data dengan satu subquery, bukan
        | dengan memuat seluruh episode setiap drama ke memori.
        |
        | Drama yang belum punya part sama sekali ikut tidak muncul, dan itu
        | memang benar: tidak ada yang bisa dipasangi. Buat partnya dulu
        | lewat tombol "+ Part" di panel atas, dan dramanya muncul sendiri.
        */
        $dramas = Drama::query()
            ->select('id', 'title')
            ->whereHas('episodes', fn ($q) => $q->whereDoesntHave('video'))
            ->orderBy('title')
            ->get();

        $jumlah = [
            'tersedia'  => $this->belumTerpasang(VideoInbox::query())->count(),
            'terpasang' => $this->sudahTerpasang(VideoInbox::query())->count(),
        ];

        return view('web.pages.admin.video-inbox', compact(
            'videos',
            'dramas',
            'tampil',
            'jumlah'
        ));
    }

    /**
     * Video yang masih menunggu dipasang.
     *
     * Dua syarat, bukan satu. `status` bisa saja masih 'available' sementara
     * `episode_id` sudah terisi bila ada proses yang berhenti di tengah —
     * dan video seperti itu tidak boleh muncul sebagai pekerjaan yang belum
     * selesai, karena memasangnya lagi akan ditolak di tahap berikutnya.
     * Syarat yang sama dipakai `VideoInbox::isAvailable()`.
     */
    private function belumTerpasang(Builder $query): Builder
    {
        return $query->where('status', 'available')->whereNull('episode_id');
    }

    /** Kebalikannya persis, supaya tidak ada video yang hilang dari keduanya. */
    private function sudahTerpasang(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->where('status', '!=', 'available')->orWhereNotNull('episode_id')
        );
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
            'pairs'              => ['required', 'array', 'min:1'],
            'pairs.*.video_id'   => ['required', 'integer', 'distinct'],
            'pairs.*.episode_id' => ['required', 'integer'],
        ], [
            'pairs.required'              => 'Centang minimal satu video dan pilih partnya.',
            'pairs.*.episode_id.required' => 'Ada video yang dicentang tetapi belum dipilih partnya.',
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
                    'Ada dua video atau lebih yang diarahkan ke part yang sama. Perbaiki dulu pilihannya.'
                );
        }

        $videos = VideoInbox::query()
            ->with('provider')
            ->whereIn('id', $pairs->pluck('video_id'))
            ->get()
            ->keyBy('id');

        // Tiap baris memilih dramanya sendiri, jadi satu permintaan boleh
        // memuat episode dari drama yang berbeda-beda.
        $episodes = Episode::query()
            ->with(['video:id,episode_id', 'drama:id,title'])
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
                $dilewati[] = $nama.': part tujuan tidak ditemukan.';
                continue;
            }

            // Episode yang sudah punya video tidak ditimpa. Ini keputusan
            // sadar: menimpa berarti kehilangan berkas lama tanpa jalan
            // kembali, dan salah centang jauh lebih mudah terjadi daripada
            // niat mengganti video.
            if ($episode->video !== null) {
                $dilewati[] = $nama.': '
                    .($episode->drama?->title ?? 'Drama').' Part '
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
     * Membatalkan satu pemasangan: partnya dikosongkan, videonya kembali.
     *
     * Salah pilih part adalah kesalahan yang paling mudah terjadi di halaman
     * ini — nomor part ditebak dari nama berkas, dan tebakan itu sesekali
     * meleset tanpa terlihat sampai ada yang menonton. Sampai sekarang satu-
     * satunya jalan keluarnya ada di halaman lain, dan admin yang baru sadar
     * salah pasang harus meninggalkan daftar yang sedang dikerjakannya.
     *
     * Yang dilakukan hanya membalik apa yang dilakukan `pasang()`: baris
     * `episode_videos` dihapus sehingga partnya kosong lagi, dan baris inbox
     * dikembalikan ke `available` sehingga videonya muncul lagi di tab Belum
     * terpasang beserta kedua kotak pilihannya. Dari sana drama dan partnya
     * dipilih ulang lewat alur yang sama seperti biasa — tidak ada jalur
     * pemasangan kedua yang harus dijaga tetap sama dengan yang pertama.
     *
     * **Berkasnya tidak disentuh.** Object di storage provider tetap di
     * tempatnya, dan itulah yang membuat tindakan ini aman dilakukan begitu
     * ragu muncul: yang hilang cuma catatan, dan catatan itu dibuat ulang
     * dalam satu kali pasang.
     */
    public function release(VideoInbox $video): RedirectResponse
    {
        if ($video->isAvailable()) {
            return back()->with(
                'error',
                'Video ini memang belum terpasang ke part mana pun.'
            );
        }

        $nama = $video->original_filename ?: 'Video #'.$video->id;

        $episode = Episode::query()
            ->with(['video', 'drama:id,title'])
            ->find($video->episode_id);

        /*
        | Baris `episode_videos` hanya dihapus bila ia memang menunjuk berkas
        | INI.
        |
        | Part yang sudah dipasangi masih bisa diunggahi berkas lain lewat
        | halaman Unggah — `updateOrCreate` di `pasang()` memakai kunci
        | `episode_id`, jadi barisnya ditimpa, bukan ditambah. Bila itu yang
        | terjadi, yang duduk di part tersebut bukan lagi video ini, dan
        | menghapusnya berarti membuang catatan berkas yang masih dipakai
        | orang lain.
        |
        | Dalam keadaan itu baris inbox tetap dilepas — statusnya memang sudah
        | tidak menggambarkan apa pun — dan admin diberi tahu bahwa partnya
        | sengaja dibiarkan berisi.
        */
        $terpasang = $episode?->video;

        $milikVideoIni = $terpasang !== null
            && (int) $terpasang->storage_provider_id === (int) $video->storage_provider_id
            && ltrim((string) $terpasang->object_key, '/') === ltrim((string) $video->object_key, '/');

        DB::transaction(function () use ($video, $terpasang, $milikVideoIni) {
            if ($milikVideoIni) {
                $terpasang->delete();
            }

            $video->update([
                'status'      => 'available',
                'episode_id'  => null,
                'assigned_at' => null,
            ]);
        });

        $tujuan = $episode === null
            ? 'part yang sudah tidak ada'
            : ($episode->drama?->title ?? 'Drama').' Part '
                .str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT);

        if ($terpasang !== null && ! $milikVideoIni) {
            return back()->with(
                'success',
                $nama.' kembali ke daftar Belum terpasang. '
                .$tujuan.' tidak ikut dikosongkan karena video yang ada di sana '
                .'sekarang berkas lain.'
            );
        }

        return back()->with(
            'success',
            $nama.' dilepas dari '.$tujuan.'. Partnya kosong lagi dan videonya '
            .'menunggu di tab Belum terpasang — pilih drama dan partnya di sana. '
            .'Berkasnya masih utuh di storage.'
        );
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
