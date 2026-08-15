<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChannelPost;
use App\Models\Drama;
use App\Services\Admin\ActivityLogger;
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
        protected ChannelPostService $channel
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
            return back()->with('error', 'Tidak ada episode pada rentang itu. Postingan dibatalkan.');
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
}
