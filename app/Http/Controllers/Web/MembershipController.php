<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class MembershipController extends Controller
{
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

        return view('web.pages.membership', compact('plans', 'subscriptions'));
    }
}
