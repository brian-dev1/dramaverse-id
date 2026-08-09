<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferralCommission;
use App\Models\ReferralTier;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel admin Program Affiliate.
 *
 * Satu halaman dengan empat tab: pengaturan, tingkatan komisi, daftar komisi,
 * dan penarikan. Admin tidak pernah mengubah saldo langsung — saldo adalah
 * hasil hitung dari komisi dan penarikan, jadi yang bisa diubah hanyalah
 * status keduanya. Itu yang membuat angkanya bisa diaudit.
 */
class ReferralController extends Controller
{
    public function __construct(protected ReferralService $referral)
    {
    }

    public function index(Request $request): View
    {
        $tab = $request->string('tab', 'ringkasan')->toString();

        $komisi = ReferralCommission::with(['referrer:id,name,telegram_username', 'referredUser:id,name,telegram_username', 'invoice:id,number,total'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $cari = '%'.$request->string('q').'%';
                $q->whereHas('referrer', fn ($r) => $r->where('name', 'like', $cari)
                    ->orWhere('telegram_username', 'like', $cari));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $penarikan = ReferralWithdrawal::with('user:id,name,telegram_username')
            ->when($request->filled('wstatus'), fn ($q) => $q->where('status', $request->string('wstatus')))
            ->latest()
            ->paginate(25, ['*'], 'wpage')
            ->withQueryString();

        // Papan peringkat affiliate — sekaligus alat deteksi kecurangan:
        // pengundang dengan banyak undangan tapi nol transaksi patut dilihat.
        $peringkat = User::query()
            ->select('users.id', 'users.name', 'users.telegram_username', 'users.referral_code')
            ->selectRaw('(select count(*) from users u2 where u2.referred_by_id = users.id) as total_referral')
            // Binding dipakai, bukan tanda kutip di dalam string SQL: kutip
            // ganda diperlakukan berbeda antar mode SQL, dan pernah membuat
            // subquery seperti ini gagal diam-diam di server dengan
            // ANSI_QUOTES menyala.
            ->selectRaw('(select coalesce(sum(rc.amount),0) from referral_commissions rc where rc.referrer_id = users.id and rc.status <> ?) as total_komisi', ['void'])
            ->selectRaw('(select count(*) from referral_commissions rc where rc.referrer_id = users.id and rc.status <> ?) as total_transaksi', ['void'])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('users as u3')->whereColumn('u3.referred_by_id', 'users.id'))
            ->orderByDesc('total_komisi')
            ->take(20)
            ->get();

        return view('web.pages.admin.referral', [
            'tab'        => $tab,
            'config'     => $this->referral->config(),
            'tiers'      => ReferralTier::orderBy('level')->get(),
            'ewallets'   => $this->referral->ewallets(),
            'komisi'     => $komisi,
            'penarikan'  => $penarikan,
            'peringkat'  => $peringkat,
            'statistik'  => [
                'komisi_total'     => (float) ReferralCommission::where('status', '!=', 'void')->sum('amount'),
                'komisi_tersedia'  => (float) ReferralCommission::where('status', 'available')->sum('amount'),
                'komisi_dibayar'   => (float) ReferralWithdrawal::where('status', 'paid')->sum('amount'),
                'menunggu_tarik'   => ReferralWithdrawal::where('status', 'pending')->count(),
                'total_referral'   => User::whereNotNull('referred_by_id')->count(),
                'transaksi'        => ReferralCommission::where('status', '!=', 'void')->count(),
            ],
        ]);
    }

    /** Simpan pengaturan umum. */
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'referral_enabled'      => ['nullable', 'boolean'],
            'referral_min_withdraw' => ['required', 'numeric', 'min:0'],
            'referral_fee_percent'  => ['required', 'numeric', 'min:0', 'max:100'],
            'referral_cookie_days'  => ['required', 'integer', 'min:1', 'max:365'],
            'referral_hold_days'    => ['required', 'integer', 'min:0', 'max:90'],
            'referral_base'         => ['required', 'in:subtotal,total'],
            'referral_ewallets'     => ['required', 'string', 'max:255'],
        ]);

        $data['referral_enabled'] = $request->boolean('referral_enabled') ? '1' : '0';

        $this->referral->saveConfig($data);

        return back()->with('status', 'Pengaturan affiliate disimpan.');
    }

    /** Simpan seluruh tingkatan komisi sekaligus. */
    public function updateTiers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tiers'                  => ['required', 'array', 'min:1', 'max:10'],
            'tiers.*.id'             => ['nullable', 'integer'],
            'tiers.*.level'          => ['required', 'integer', 'min:1', 'max:20'],
            'tiers.*.rate'           => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.min_referrals'  => ['required', 'integer', 'min:0'],
            'tiers.*.is_active'      => ['nullable'],
        ]);

        DB::transaction(function () use ($data) {
            $dipakai = [];

            foreach ($data['tiers'] as $baris) {
                $tier = ReferralTier::updateOrCreate(
                    ['level' => $baris['level']],
                    [
                        'rate'          => $baris['rate'],
                        'min_referrals' => $baris['min_referrals'],
                        'is_active'     => (bool) ($baris['is_active'] ?? false),
                    ]
                );

                $dipakai[] = $tier->id;
            }

            ReferralTier::whereNotIn('id', $dipakai)->delete();
        });

        $this->referral->flush();

        return back()->with('status', 'Tingkatan komisi diperbarui.');
    }

    /** Batalkan komisi (mis. pembayaran di-refund atau terbukti curang). */
    public function voidCommission(Request $request, int $id): RedirectResponse
    {
        $komisi = ReferralCommission::findOrFail($id);

        if ($komisi->status === 'paid') {
            return back()->with('error', 'Komisi ini sudah ikut dibayarkan; tidak bisa dibatalkan.');
        }

        $komisi->update([
            'status' => 'void',
            'note'   => $request->string('note')->toString() ?: 'Dibatalkan admin.',
        ]);

        return back()->with('status', 'Komisi dibatalkan.');
    }

    /** Kembalikan komisi yang sempat dibatalkan. */
    public function restoreCommission(int $id): RedirectResponse
    {
        ReferralCommission::whereKey($id)->where('status', 'void')
            ->update(['status' => 'available', 'note' => null]);

        return back()->with('status', 'Komisi dipulihkan.');
    }

    /**
     * Proses penarikan.
     *
     * approved → sudah disetujui, uang sedang dikirim.
     * paid     → uang sudah dikirim; komisi yang menutupinya ditandai `paid`.
     * rejected → saldo kembali utuh ke pengguna.
     */
    public function processWithdrawal(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approved,paid,rejected'],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $id, $request) {
            $tarik = ReferralWithdrawal::lockForUpdate()->findOrFail($id);

            if ($tarik->status === 'paid') {
                return;
            }

            $tarik->update([
                'status'       => $data['action'],
                'note'         => $data['note'] ?? null,
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);

            if ($data['action'] === 'paid') {
                // Tandai komisi tertua senilai penarikan sebagai sudah dibayar,
                // supaya jejak uangnya bisa ditelusuri per invoice.
                $sisa = (float) $tarik->amount;

                $komisi = ReferralCommission::where('referrer_id', $tarik->user_id)
                    ->where('status', 'available')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($komisi as $k) {
                    if ($sisa <= 0) {
                        break;
                    }

                    $k->update(['status' => 'paid']);
                    $sisa -= (float) $k->amount;
                }
            }
        });

        return back()->with('status', 'Penarikan diperbarui.');
    }
}
