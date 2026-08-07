<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramDelivery;
use App\Models\User;
use App\Services\Telegram\TelegramRetentionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pemantauan video yang dikirim bot dan penarikannya kembali.
 *
 * ## Kenapa ini punya halaman sendiri
 *
 * Penarikan video bersifat *best-effort* — Telegram menolak menghapus pesan
 * yang usianya lewat 48 jam, dan itu tidak bisa diakali. Tanpa halaman ini,
 * kegagalan yang wajar itu tidak terlihat di mana pun: admin akan mengira
 * setiap video premium selalu berhasil ditarik, sampai ada yang melapor masih
 * bisa menonton dari riwayat chat-nya.
 *
 * Yang ditampilkan apa adanya: berapa yang terhapus, berapa yang terlalu tua,
 * berapa yang gagal karena bot diblokir.
 */
class TelegramRetentionController extends Controller
{
    private const SORTABLE = [
        'sent_at'    => 'sent_at',
        'id'         => 'id',
        'delete_after' => 'delete_after',
    ];

    public function __construct(
        protected TelegramRetentionService $retention
    ) {
    }

    public function index(Request $request): View
    {
        $query = TelegramDelivery::query()->with(['user', 'episode.drama']);

        if (filled($status = $request->query('status'))) {
            $query->where('delete_status', $status);
        }

        if ($request->query('premium') === '1') {
            $query->where('is_premium', true);
        }

        // Pencarian pengguna: id numerik, telegram_id, atau nama.
        if (filled($cari = trim((string) $request->query('q')))) {
            $ids = User::query()
                ->where('id', $cari)
                ->orWhere('telegram_id', $cari)
                ->orWhere('name', 'like', '%'.$cari.'%')
                ->limit(50)
                ->pluck('id');

            $query->whereIn('user_id', $ids);
        }

        // Kolom sort dari daftar tertutup. Nama kolom dari query string tidak
        // pernah masuk orderBy.
        $sort = self::SORTABLE[$request->query('sort')] ?? 'sent_at';
        $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $stats = [
            'total'   => TelegramDelivery::where('is_premium', true)->count(),
            'pending' => TelegramDelivery::where('is_premium', true)
                ->where('delete_status', TelegramDelivery::PENDING)->count(),
            'deleted' => TelegramDelivery::where('delete_status', TelegramDelivery::DELETED)->count(),
            'too_old' => TelegramDelivery::where('delete_status', TelegramDelivery::TOO_OLD)->count(),
            'failed'  => TelegramDelivery::where('delete_status', TelegramDelivery::FAILED)->count(),
        ];

        return view('web.pages.admin.telegram-retention', [
            'rows'   => $query->orderBy($sort, $dir)->paginate(30)->withQueryString(),
            'stats'  => $stats,
            'filter' => $request->only(['status', 'premium', 'q', 'sort', 'dir']),
            'config' => [
                'ttl_hours' => (int) config('telegram.retention.ttl_hours'),
                'on_expire' => (bool) config('telegram.retention.on_expire'),
            ],
        ]);
    }

    /** Jalankan penarikan sekarang untuk satu pengguna. */
    public function purgeUser(Request $request, User $user): RedirectResponse
    {
        $hasil = $this->retention->tarikMilikPengguna($user->id);

        return back()->with('status', sprintf(
            'Penarikan untuk %s: %d dihapus, %d lewat 48 jam, %d gagal.',
            $user->name ?? ('#'.$user->id),
            $hasil['dihapus'],
            $hasil['terlalu_tua'],
            $hasil['gagal']
        ));
    }

    /** Jalankan antrean penghapusan terjadwal sekarang, tanpa menunggu cron. */
    public function runNow(): RedirectResponse
    {
        $hasil = $this->retention->jalankanTerjadwal();

        $tua = $this->retention->tandaiTerlaluTua();

        return back()->with('status', sprintf(
            '%d dihapus, %d gagal, %d ditandai lewat 48 jam.',
            $hasil['dihapus'],
            $hasil['gagal'],
            $hasil['terlalu_tua'] + $tua
        ));
    }
}
