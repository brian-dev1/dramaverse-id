<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use App\Models\VideoInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoInboxController extends Controller
{
    public function store(Request $request): JsonResponse
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

        $data = $request->validate([
            'provider_slug'       => ['required', 'string', 'max:100'],
            'telegram_message_id' => ['nullable', 'integer'],
            'original_filename'   => ['required', 'string', 'max:255'],
            'object_key'          => ['required', 'string', 'max:900'],
            'mime_type'           => ['nullable', 'string', 'max:150'],
            'size'                => ['nullable', 'integer', 'min:0'],
            'checksum'            => ['nullable', 'string', 'size:64'],
            'public_url'          => ['nullable', 'string', 'max:1000'],
        ]);

        $provider = StorageProvider::query()
            ->where('slug', $data['provider_slug'])
            ->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Storage provider tidak ditemukan.',
            ], 422);
        }

        $video = VideoInbox::updateOrCreate(
            [
                'storage_provider_id' => $provider->id,
                'object_key'          => $data['object_key'],
            ],
            [
                'telegram_message_id' => $data['telegram_message_id'] ?? null,
                'original_filename'   => $data['original_filename'],
                'mime_type'           => $data['mime_type'] ?? 'video/mp4',
                'size'                => $data['size'] ?? 0,
                'checksum'            => $data['checksum'] ?? null,
                'public_url'          => $data['public_url'] ?? null,
                'uploaded_at'         => now(),
            ]
        );

        return response()->json([
            'message' => 'Video berhasil disinkronkan.',
            'data' => [
                'id'         => $video->id,
                'status'     => $video->status,
                'object_key' => $video->object_key,
            ],
        ], $video->wasRecentlyCreated ? 201 : 200);
    }
}