<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ReferralWithdrawal;
use App\Services\ReferralService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Program Affiliate untuk pengguna.
 */
class AffiliateController extends Controller
{
    public function __construct(protected ReferralService $referral)
    {
    }

    public function index(Request $request): View
    {
        abort_unless($this->referral->enabled(), 404);

        $user = Auth::user();
        $hari = (int) $request->integer('range', 7);
        $hari = in_array($hari, [7, 30, 90], true) ? $hari : 7;

        return view('web.pages.affiliate', [
            'user'        => $user,
            'summary'     => $this->referral->summary($user),
            'series'      => $this->referral->series($user, $hari),
            'range'       => $hari,
            'tiers'       => $this->referral->tiers(),
            'ewallets'    => $this->referral->ewallets(),
            'withdrawals' => ReferralWithdrawal::where('user_id', $user->id)
                                ->latest()->take(20)->get(),
            'invited'     => $user->referrals()
                                ->select('id', 'name', 'telegram_username', 'referred_at', 'is_premium')
                                ->latest('referred_at')->take(20)->get(),
        ]);
    }

    /**
     * Data untuk pembaruan langsung di halaman (polling tiap beberapa detik).
     */
    public function stats(Request $request): JsonResponse
    {
        abort_unless($this->referral->enabled(), 404);

        $user = Auth::user();
        $hari = (int) $request->integer('range', 7);
        $hari = in_array($hari, [7, 30, 90], true) ? $hari : 7;

        return response()->json([
            'summary' => $this->referral->summary($user),
            'series'  => $this->referral->series($user, $hari),
        ]);
    }

    public function withdraw(Request $request): RedirectResponse
    {
        abort_unless($this->referral->enabled(), 404);

        $data = $request->validate([
            'method'         => ['required', 'string', 'in:'.implode(',', $this->referral->ewallets())],
            'account_number' => ['required', 'string', 'max:64', 'regex:/^[0-9+\- ]+$/'],
            'account_name'   => ['required', 'string', 'max:128'],
        ], [
            'account_number.regex' => 'Nomor e-wallet hanya boleh berisi angka.',
        ]);

        try {
            $this->referral->requestWithdrawal(
                Auth::user(),
                $data['method'],
                $data['account_number'],
                $data['account_name'],
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Penarikan diajukan. Menunggu diproses admin.');
    }
}
