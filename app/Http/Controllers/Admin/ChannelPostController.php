<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChannelAnnouncement;
use App\Models\ChannelPost;
use App\Models\Drama;
use App\Services\Admin\ActivityLogger;
use App\Services\Telegram\ChannelAnnouncementService;
use App\Services\Telegram\ChannelBulkService;
use App\Services\Telegram\ChannelPostService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kirim katalog drama ke channel Telegram.
 *
 * Alurnya sengaja dua langkah: admin memilih drama dan rentang episodenya,
 * melihat pratinjau caption apa adanya, baru menekan Kirim.
 *
 * Pratinjau dan pengiriman memakai `ChannelPostService::susun()` yang sama
 * persis. Menyusun pratinjau dengan kode terpisah — sekadar "kira-kira
 * begini" — adalah pratinjau yang suatu saat berbeda dari yang terkirim, dan
 * yang menemukan perbedaannya adalah pembaca channel.
 */
class ChannelPostController extends Controller
{
    public function __construct(
        protected ChannelPostService $channel,
        protected ChannelBulkService $massal,
        protected ChannelAnnouncementService $pengumuman
    ) {
    }

    public function index(Request $request): View
    {
        // `genres` dan `country` dipakai template caption. Dimuat di sini agar
        // pratinjau tidak memicu query per placeholder.
        $drama = $request->filled('drama_id')
            ? Drama::with(['country:id,name', 'genres:id,name'])->find((int) $request->query('drama_id'))
            : null;

        $dari   = $request->filled('from') ? max(1, (int) $request->query('from')) : null;
        $sampai = $request->filled('to') ? max(1, (int) $request->query('to')) : null;

        // Rentang terbalik diperbaiki diam-diam, bukan ditolak. Admin yang
        // mengetik "10 sampai 5" jelas bermaksud 5 sampai 10, dan menolaknya
        // dengan pesan galat hanya menambah satu putaran tanpa gunanya.
        if ($dari !== null && $sampai !== null && $dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $potongan = $drama !== null
            ? $this->channel->susun($drama, $dari, $sampai)
            : [];

        return view('web.pages.admin.channel-post', [
            'dramas'   => Drama::query()
                ->select(['id', 'title', 'total_episode'])
                ->orderBy('title')
                ->get(),
            'drama'    => $drama,
            'dari'     => $dari,
            'sampai'   => $sampai,
            'potongan' => $potongan,

            /*
            | Panjang tiap potongan dihitung di sini, memakai hitungan
            | ChannelPostService — bukan `mb_strlen()` di dalam blade.
            |
            | Angka yang dipakai pengirim untuk memutuskan pecah-tidaknya
            | postingan harus sama dengan angka yang dibaca admin di
            | pratinjau; hitungan kedua yang berdiri sendiri di view adalah
            | tempat keduanya diam-diam berselisih.
            */
            'panjang'       => array_map(
                fn (string $teks) => $this->channel->panjangTelegram($teks),
                $potongan
            ),
            'batasCaption'  => $this->channel->batasCaption(),

            'episodes' => $drama !== null ? $this->channel->episodes($drama, $dari, $sampai) : collect(),
            'penghalang' => $this->channel->penghalang(),
            'chatId'     => $this->channel->chatId(),
            /*
            | Data panel "kirim banyak sekaligus".
            |
            | `sudahDikirim` satu query untuk seluruh tabel, bukan
            | `pernahDikirim()` per baris — daftar dramanya bisa ratusan, dan
            | satu query per baris adalah halaman admin yang melambat diam-diam
            | seiring katalognya bertambah.
            */
            'sudahDikirim' => $this->massal->sudahDikirim(),
            'bulkMax'      => ChannelBulkService::LIMIT,
            'bulkJeda'     => ChannelBulkService::JEDA_DETIK,

            /*
            | Panel pengumuman menumpang di halaman ini, tapi seluruh
            | aksinya milik ChannelAnnouncementController. Yang dikerjakan di
            | sini cuma menyediakan daftarnya untuk ditampilkan — panel yang
            | datanya diambil lewat query di dalam blade adalah query yang
            | tidak pernah terlihat siapa pun sampai halamannya melambat.
            */
            'pengumuman' => ChannelAnnouncement::query()
                ->with('author:id,name')
                ->latest('id')
                ->limit(15)
                ->get(),

            'maxTombol'  => ChannelAnnouncementController::MAX_TOMBOL,

            /*
            | Penghalang pengumuman DIHITUNG TERPISAH dari penghalang katalog.
            |
            | Yang di atas ikut mensyaratkan TELEGRAM_BOT_USERNAME, karena
            | tiap baris episode berisi tautan ke bot. Pengumuman tidak
            | membuat tautan apa pun sendiri; memakai penghalang yang sama
            | berarti tombol Kirim Pengumuman mati dengan alasan yang tidak
            | ada hubungannya dengan pengumuman.
            */
            'penghalangPengumuman' => $this->pengumuman->penghalang(),

            'riwayat'  => ChannelPost::query()
                ->with(['drama:id,title', 'sender:id,name'])
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'drama_id' => ['required', 'integer', 'exists:dramas,id'],
            'from'     => ['nullable', 'integer', 'min:1', 'max:100000'],
            'to'       => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        if ($alasan = $this->channel->penghalang()) {
            return back()->with('error', $alasan);
        }

        $drama = Drama::with(['country:id,name', 'genres:id,name'])->findOrFail($data['drama_id']);

        $dari   = $data['from'] ?? null;
        $sampai = $data['to'] ?? null;

        if ($dari !== null && $sampai !== null && $dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        // Postingan tanpa satu pun episode adalah poster dan judul saja —
        // tidak ada yang bisa ditekan pembacanya. Ditahan di sini, bukan
        // dibiarkan terkirim lalu dihapus manual dari channel.
        if ($this->channel->episodes($drama, $dari, $sampai)->isEmpty()) {
            return back()->with('error', 'Tidak ada part pada rentang itu. Postingan dibatalkan.');
        }

        $catatan = $this->channel->kirim(
            $drama,
            $dari,
            $sampai,
            ChannelPost::SOURCE_MANUAL,
            $request->user()
        );

        app(ActivityLogger::class)->log('kirim-channel', 'drama', $drama, [
            'rentang' => $catatan->rentang(),
            'status'  => $catatan->status,
        ]);

        return $catatan->berhasil()
            ? back()->with('status',
                "Terkirim ke channel: {$drama->title}, {$catatan->rentang()} "
                ."({$catatan->episode_count} episode, ".count($catatan->message_ids ?? [])." pesan).")
            : back()->with('error', 'Gagal mengirim: '.$catatan->error);
    }

    /**
     * Kirim beberapa drama sekaligus.
     *
     * Terpisah dari `send()` dan tidak menyentuhnya. Keduanya memang berbeda
     * sifat: `send()` mengirim satu drama yang captionnya baru saja dilihat
     * admin di pratinjau dan melaporkan hasilnya seketika; yang ini menerima
     * pilihan tanpa pratinjau, jadi ia hanya boleh melaporkan berapa yang
     * diantrekan — hasil tiap drama menyusul di tabel Riwayat.
     *
     * Rentang part sengaja tidak diterima di sini. Rentang "part 5-10" masuk
     * akal untuk satu drama tertentu; diterapkan ke sepuluh drama sekaligus
     * ia berarti sepuluh postingan yang dipotong di tempat yang tidak ada
     * hubungannya dengan isi masing-masing.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids'     => ['required', 'array', 'min:1'],
            'ids.*'   => ['integer'],
            'ulangi'  => ['nullable', 'boolean'],
        ], [
            'ids.required' => 'Pilih dulu drama yang ingin dikirim.',
        ]);

        // Tanda centang "kirim ulang" dibalik jadi "lewati yang sudah
        // dikirim": bawaannya melewati, dan admin harus sengaja mencentang
        // untuk menghasilkan postingan kedua atas drama yang sama.
        $lewati = ! $request->boolean('ulangi');

        $hasil = $this->massal->kirim($data['ids'], $request->user(), $lewati);

        app(ActivityLogger::class)->log('kirim-channel-massal', 'drama', null, [
            'diminta'  => count($data['ids']),
            'diantre'  => $hasil['queued'],
            'dilewati' => count($hasil['skipped']),
            'ulangi'   => ! $lewati,
        ]);

        if ($hasil['queued'] === 0) {
            return back()->with('error', 'Tidak ada yang diantrekan. '.implode(' ', array_slice($hasil['skipped'], 0, 5)));
        }

        $pesan = $hasil['queued'].' drama diantrekan ke channel';

        if ($hasil['perkiraan'] > 0) {
            $pesan .= ', selesai dalam ~'.$hasil['perkiraan'].' menit';
        }

        $pesan .= '. Hasil tiap drama muncul di Riwayat kiriman.';

        if ($hasil['skipped'] !== []) {

            $pesan .= ' Dilewati '.count($hasil['skipped']).': '
                .implode(' ', array_slice($hasil['skipped'], 0, 5));

            if (count($hasil['skipped']) > 5) {
                $pesan .= ' (dan lainnya)';
            }
        }

        return back()->with('status', $pesan);
    }
}
