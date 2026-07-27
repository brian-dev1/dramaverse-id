<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PremiumMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if (
            !$user->is_premium ||
            (
                $user->premium_expired_at &&
                now()->greaterThan($user->premium_expired_at)
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Premium membership required.'
            ], 403);
        }

        return $next($request);
    }
}