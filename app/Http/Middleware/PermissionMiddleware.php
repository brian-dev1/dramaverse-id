<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\PermissionService;

class PermissionMiddleware
{
    public function __construct(
        protected PermissionService $service
    ) {
    }

    public function handle(
        $request,
        Closure $next,
        string $permission
    ) {

        $user = auth()->user();

        if (!$user) {

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);

        }

        if (!$this->service->can(
            $user,
            $permission
        )) {

            return response()->json([
                'success' => false,
                'message' => 'Permission denied.'
            ], 403);

        }

        return $next($request);
    }
}