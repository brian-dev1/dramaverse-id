<?php

namespace App\Http\Controllers\Web;

use App\Enums\DramaRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\DramaRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Permintaan drama dari sisi pengguna.
 *
 * Satu halaman untuk dua hal: mengirim permintaan baru, dan melihat nasib
 * permintaan yang sudah dikirim. Memisahkannya ke dua halaman berarti orang
 * yang ingin tahu statusnya harus mencari halaman kedua, dan yang paling
 * sering ditanyakan setelah mengirim adalah "sudah diproses belum".
 */
class DramaRequestController extends Controller
{
    /** Batas permintaan yang boleh menggantung sekaligus. */
    private const BATAS_TERBUKA = 10;

    public function index(): View
    {
        $user = Auth::user();

        return view('web.pages.request', [
            'requests' => DramaRequest::query()
                ->with('drama:id,title,slug,poster')
                ->where('user_id', $user->id)
                ->latest('id')
                ->get(),
            'terbuka'  => DramaRequest::query()
                ->where('user_id', $user->id)
                ->terbuka()
                ->count(),
            'batas'    => self::BATAS_TERBUKA,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'year'  => ['nullable', 'string', 'max:10'],
            'note'  => ['nullable', 'string', 'max:500'],
        ], [], [
            'title' => 'judul drama',
            'year'  => 'tahun',
            'note'  => 'catatan',
        ]);

        $user = Auth::user();

        /*
        |----------------------------------------------------------------------
        | Tiga penjagaan, dan alasannya berbeda-beda
        |----------------------------------------------------------------------
        |
        | Yang pertama menjaga admin: sepuluh permintaan menggantung dari satu
        | orang sudah cukup untuk dikerjakan, dan yang kesebelas hanya
        | menambah panjang daftar tanpa menambah informasi.
        |
        | Yang kedua menjaga penggunanya sendiri dari kebingungan. Mengirim
        | judul yang sama dua kali menghasilkan dua baris berstatus berbeda di
        | halaman ini, dan tidak ada cara menebak mana yang akan dijawab.
        |
        | Yang ketiga menjawab pertanyaannya lebih baik daripada permintaan:
        | kalau dramanya ternyata sudah ada di katalog, yang dibutuhkan adalah
        | tautannya sekarang, bukan janji pemberitahuan nanti.
        |
        */
        $terbuka = DramaRequest::where('user_id', $user->id)->terbuka()->count();

        if ($terbuka >= self::BATAS_TERBUKA) {
            return back()->with('error',
                'Anda punya '.$terbuka.' permintaan yang masih diproses. '
                .'Tunggu sebagian selesai dulu sebelum menambah yang baru.');
        }

        $normal = DramaRequest::normalkan($data['title']);

        $kembar = DramaRequest::where('user_id', $user->id)
            ->get(['id', 'title', 'status'])
            ->first(fn (DramaRequest $r) => DramaRequest::normalkan($r->title) === $normal);

        if ($kembar !== null) {
            return back()->with('error',
                'Anda sudah pernah meminta "'.$kembar->title.'" — statusnya sekarang: '
                .$kembar->status->label().'.');
        }

        DramaRequest::create([
            'user_id' => $user->id,
            'title'   => trim($data['title']),
            'year'    => $data['year'] ?? null,
            'note'    => $data['note'] ?? null,
            'status'  => DramaRequestStatus::PENDING,
        ]);

        return back()->with('status',
            'Permintaan terkirim. Anda akan diberi tahu lewat Telegram begitu dramanya tersedia.');
    }

    /**
     * Batalkan permintaan sendiri.
     *
     * Hanya yang belum selesai. Permintaan yang sudah dijawab adalah catatan
     * bahwa admin pernah mengerjakannya, dan menghapusnya menghilangkan
     * satu-satunya bukti bahwa pemberitahuannya memang pernah dikirim.
     */
    public function destroy(int $id): RedirectResponse
    {
        $permintaan = DramaRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($permintaan->status->selesai()) {
            return back()->with('error', 'Permintaan yang sudah selesai tidak bisa dibatalkan.');
        }

        $permintaan->delete();

        return back()->with('status', 'Permintaan dibatalkan.');
    }
}
