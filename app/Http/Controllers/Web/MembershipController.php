<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class MembershipController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gateways
    ) {
    }

    /** Daftar paket membership — dapat diakses tanpa login. */
    public function index(): View
    {
        $plans = MembershipPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        $subscriptions = collect();

        if (Auth::check()) {
            $subscriptions = Auth::user()
                ->subscriptions()
                ->with('plan')
                ->latest()
                ->take(10)
                ->get();
        }

        // Hanya provider yang benar-benar siap. Menampilkan metode yang
        // kredensialnya belum lengkap berarti pengguna sampai di checkout
        // lalu ditolak -- tempat paling buruk untuk gagal.
        $providers = $this->gateways->usable();

        return view('web.pages.membership', compact('plans', 'subscriptions', 'providers'));
    }
}
