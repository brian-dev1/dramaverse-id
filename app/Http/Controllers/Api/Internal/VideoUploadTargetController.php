<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoUploadTargetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) config('services.video_worker.token');

        if (
            $configuredToken === '' ||
            ! hash_equals(
                $configuredToken,
                (string) $request->bearerToken()
            )
        ) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $providers = StorageProvider::query()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->get([
                'id',
                'name',
                'slug',
                'driver',
                'bucket',
                'endpoint',
                'region',
                'public_url',
                'visibility',
                'is_default',
            ]);

        return response()->json([
            'default_provider' => optional(
                $providers->firstWhere('is_default', true)
            )?->slug,

            'providers' => $providers,
        ]);
    }
}