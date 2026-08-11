<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DramaRequestStatus;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyDramaRequestFulfilled;
use App\Models\Drama;
use App\Models\DramaRequest;
use App\Services\Admin\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Permintaan drama dari sisi admin.
 *
 * Halaman ini menjawab satu pertanyaan: drama apa yang paling banyak diminta
 * dan belum ada. Karena itu urutan bawaannya bukan yang terbaru, melainkan
 * yang paling sering diminta — daftar berurutan waktu membuat judul yang
 * diminta dua puluh orang tenggelam di bawah judul yang diminta satu orang
 * kemarin sore.
 */
class DramaRequestController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $daftar = DramaRequest::query()
            ->with(['user:id,name,telegram_username,telegram_id', 'drama:id,title,slug'])
            ->when(
                in_array($status, array_keys(DramaRequestStatus::options()), true),
                fn ($q) => $q->status($status)
            )
            ->when($request->filled('q'), function ($q) use ($request) {
                $kata = trim((string) $request->query('q'));

                $q->where('title', 'like', '%'.$kata.'%');
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        /*
        |----------------------------------------------------------------------
        | Papan peringkat judul
        |----------------------------------------------------------------------
        |
        | Dihitung dari judul yang sudah dinormalkan, bukan dari kolom mentah.
        | "Reply 1988", "reply  1988", dan "Reply-1988" adalah satu judul yang
        | sama; menghitungnya sebagai tiga baris berbeda menghasilkan papan
        | peringkat yang justru menyembunyikan judul terpopuler.
        |
        | Normalisasi dilakukan di PHP, bukan SQL, karena aturannya sama persis
        | dengan yang dipakai pendeteksi kembar di sisi pengguna — dan dua
        | aturan yang harus sama tetapi ditulis di dua bahasa berbeda adalah
        | dua aturan yang suatu saat berbeda.
        |
        */
        $populer = DramaRequest::query()
            ->terbuka()
            ->get(['id', 'title'])
            ->groupBy(fn (DramaRequest $r) => DramaRequest::normalkan($r->title))
            ->map(fn ($grup) => [
                'judul'  => $grup->first()->title,
                'jumlah' => $grup->count(),
            ])
            ->sortByDesc('jumlah')
            ->take(10)
            ->values();

        return view('web.pages.admin.drama-request', [
            'daftar'   => $daftar,
            'populer'  => $populer,
            'status'   => $status,
            'q'        => $request->query('q', ''),
            'statuses' => DramaRequestStatus::options(),
            'jumlah'   => [
                'pending' => DramaRequest::status(DramaRequestStatus::PENDING)->count(),
                'process' => DramaRequest::status(DramaRequestStatus::PROCESS)->count(),
            ],
            'dramas'   => Drama::query()->select(['id', 'title'])->orderBy('title')->get(),
        ]);
    }

    /**
     * Ubah status satu permintaan.
     *
     * Pemberitahuan hanya diantrekan saat status BERUBAH menjadi tersedia,
     * bukan setiap kali form disimpan. Admin yang memperbaiki catatan pada
     * permintaan yang sudah tersedia tidak boleh membuat pesan kedua terkirim
     * ke orang yang sudah diberi tahu kemarin.
     *
     * Penjagaan keduanya ada di job lewat `notified_at`. Yang di sini menjaga
     * antrean tetap bersih; yang di sana menjaga pengguna.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $permintaan = DramaRequest::findOrFail($id);

        $data = $request->validate([
            'status'     => ['required', Rule::enum(DramaRequestStatus::class)],
            'drama_id'   => ['nullable', 'integer', 'exists:dramas,id'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        $sebelum = $permintaan->status;

        $permintaan->forceFill([
            'status'     => $data['status'],
            'drama_id'   => $data['drama_id'] ?? null,
            'admin_note' => $data['admin_note'] ?? null,
        ])->save();

        app(ActivityLogger::class)->log('status', 'drama-request', $permintaan, [
            'dari' => $sebelum->value,
            'ke'   => $data['status'],
        ]);

        $baruTersedia = $sebelum !== DramaRequestStatus::AVAILABLE
            && $permintaan->status === DramaRequestStatus::AVAILABLE;

        if ($baruTersedia && $permintaan->bolehDiberiTahu()) {

            NotifyDramaRequestFulfilled::dispatch($permintaan->id);

            return back()->with('status',
                'Status diperbarui. Pemberitahuan sedang dikirim ke peminta '
                .'— pastikan worker antrean berjalan.');
        }

        return back()->with('status', 'Status permintaan diperbarui.');
    }

    /**
     * Kirim ulang pemberitahuan.
     *
     * Ada karena pesan Telegram bisa gagal untuk sebab di luar kendali kita —
     * pengguna memblokir bot lalu membuka blokirnya, misalnya. Tanpa tombol
     * ini, satu-satunya cara mengirim ulang adalah mengubah status bolak-balik,
     * yang justru dicegah penjagaan di atas.
     */
    public function renotify(int $id): RedirectResponse
    {
        $permintaan = DramaRequest::findOrFail($id);

        if ($permintaan->status !== DramaRequestStatus::AVAILABLE) {
            return back()->with('error', 'Hanya permintaan berstatus tersedia yang bisa diberitahukan.');
        }

        $permintaan->forceFill(['notified_at' => null])->save();

        NotifyDramaRequestFulfilled::dispatch($permintaan->id);

        return back()->with('status', 'Pemberitahuan diantrekan ulang.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $permintaan = DramaRequest::findOrFail($id);

        app(ActivityLogger::class)->log('hapus', 'drama-request', $permintaan);

        $permintaan->delete();

        return back()->with('status', 'Permintaan dihapus.');
    }
}
