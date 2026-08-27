<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\PartnerPosterSend;
use App\Services\Telegram\PartnerPosterService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kirim poster drama ke grup partner.
 *
 * Halaman terpisah dari "Kirim ke Channel" dengan sengaja. Keduanya menulis
 * ke Telegram, tetapi ke tujuan yang berbeda, dengan isi yang berbeda, untuk
 * pembaca yang berbeda. Menumpangkannya di satu halaman berarti satu tombol
 * Kirim yang artinya bergantung pada tab mana yang sedang terbuka — dan
 * kiriman yang salah alamat di sini terlihat oleh orang, bukan cuma tercatat
 * di log.
 */
class PartnerPosterController extends Controller
{
    public function __construct(
        protected PartnerPosterService $partner
    ) {
    }

    public function index(): View
    {
        $sudahDikirim = $this->partner->sudahDikirim();

        $kandidat = $this->partner->kandidat();

        return view('web.pages.admin.partner-poster', [
            'kandidat'     => $kandidat,
            'sudahDikirim' => $sudahDikirim,

            // Dihitung di sini, bukan di dalam blade. Angka yang tertulis di
            // tombol ("Kirim 12 poster") harus berasal dari sumber yang sama
            // dengan yang benar-benar diantrekan controller.
            'belum'        => $kandidat->reject(
                fn (Drama $d) => isset($sudahDikirim[$d->id])
            )->count(),

            'tanpaPoster'  => Drama::query()
                ->where(fn ($q) => $q->whereNull('poster')->orWhere('poster', ''))
                ->count(),

            'chatId'       => $this->partner->chatId(),
            'threadId'     => $this->partner->threadId(),
            'penghalang'   => $this->partner->penghalang(),
            'limit'        => PartnerPosterService::LIMIT,
            'jeda'         => PartnerPosterService::JEDA_DETIK,

            'riwayat'      => PartnerPosterSend::query()
                ->with(['drama:id,title', 'sender:id,name'])
                ->latest('id')
                ->limit(30)
                ->get(),
        ]);
    }

    /**
     * Antrekan seluruh drama yang belum pernah dikirim.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $hasil = $this->partner->antrekanSemua($request->user());

        return $this->pesan($hasil);
    }

    /**
     * Kirim ulang satu drama, termasuk yang sudah pernah dikirim.
     *
     * Endpoint sendiri, bukan parameter pada `bulk`. Yang satu berarti
     * "lanjutkan yang belum", yang ini berarti "kirim lagi yang ini walau
     * sudah pernah" — dua maksud yang berlawanan, dan menyatukannya jadi satu
     * aksi berarti satu tombol yang bisa membanjiri grup bila salah pilih.
     */
    public function one(Request $request, Drama $drama): RedirectResponse
    {
        $hasil = $this->partner->antrekan(
            [$drama->id],
            $request->user(),
            lewatiSudahDikirim: false
        );

        return $this->pesan($hasil);
    }

    /**
     * @param  array{queued:int, dilewati:array<string>, perkiraan:int}  $hasil
     */
    private function pesan(array $hasil): RedirectResponse
    {
        $redirect = redirect()->route('admin.partner-poster.index');

        if ($hasil['queued'] === 0) {

            return $redirect->with(
                'error',
                $hasil['dilewati'][0] ?? 'Tidak ada poster yang perlu dikirim.'
            );
        }

        $teks = $hasil['queued'].' poster diantrekan.';

        if ($hasil['perkiraan'] > 0) {
            $teks .= ' Perkiraan selesai sekitar '.$hasil['perkiraan'].' menit lagi.';
        }

        if ($hasil['dilewati'] !== []) {
            $teks .= ' '.count($hasil['dilewati']).' dilewati.';
        }

        // 'status', bukan 'success' — itu kunci yang dibaca layout admin.
        return $redirect->with('status', $teks);
    }
}
