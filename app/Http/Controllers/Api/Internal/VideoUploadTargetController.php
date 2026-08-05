<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class VideoUploadTargetController extends Controller
{
    /**
     * GET /api/internal/video-upload-target
     *
     * Mengembalikan provider yang bisa dipakai worker untuk video.
     */
    public function show(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->unauthorized();
        }

        $providers = StorageProvider::query()
            ->active()
            ->byPriority()
            ->get()
            ->filter(
                fn (StorageProvider $provider) =>
                    $provider->isUsable()
                    && $provider->driver->isS3Compatible()
                    && $provider->driver->supportsLargeFiles()
            )
            ->values();

        $default = $providers->firstWhere('is_default', true)
            ?? $providers->first();

        return response()->json([
            'default_provider' => $default?->slug,

            'providers' => $providers
                ->map(fn (StorageProvider $provider) => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'slug' => $provider->slug,
                    'driver' => $provider->driver->value,
                    'is_default' => (bool) $provider->is_default,
                ])
                ->values(),
        ]);
    }

    /**
     * POST /api/internal/video-upload-target
     *
     * Membuat URL PUT sementara sehingga worker dapat upload langsung
     * ke provider tanpa menerima access key / secret key.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'provider_slug' => ['nullable', 'string', 'max:100'],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:150'],
        ]);

        $provider = $this->resolveProvider(
            $validated['provider_slug'] ?? null
        );

        if (! $provider) {
            return response()->json([
                'message' => 'Storage provider tidak ditemukan atau tidak tersedia.',
            ], 422);
        }

        if (
            ! $provider->isUsable()
            || ! $provider->driver->isS3Compatible()
            || ! $provider->driver->supportsLargeFiles()
        ) {
            return response()->json([
                'message' => 'Storage provider tidak mendukung direct video upload.',
            ], 422);
        }

        try {
            $storedFilename = $this->makeStoredFilename(
                $validated['filename']
            );

            $directory = 'telegram';
            $objectKey = $directory.'/'.$storedFilename;

            if (filled($provider->root)) {
                $objectKey = trim($provider->root, '/').'/'.$objectKey;
            }

            $client = new S3Client([
                'version' => 'latest',
                'region' => $provider->effectiveRegion() ?: 'us-east-1',
                'endpoint' => $provider->normalizedEndpoint(),
                'credentials' => [
                    'key' => $provider->access_key,
                    'secret' => $provider->secret_key,
                ],
                'use_path_style_endpoint' => $provider->effectivePathStyle(),
            ]);

            $mimeType = $validated['mime_type'] ?? 'video/mp4';

            $command = $client->getCommand('PutObject', [
                'Bucket' => $provider->bucket,
                'Key' => $objectKey,
                'ContentType' => $mimeType,
            ]);

            $signedRequest = $client->createPresignedRequest(
                $command,
                '+60 minutes'
            );

            return response()->json([
                'provider' => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'slug' => $provider->slug,
                    'driver' => $provider->driver->value,
                ],

                'file' => [
                    'directory' => $directory,
                    'original_filename' => basename($validated['filename']),
                    'stored_filename' => $storedFilename,
                    'object_key' => $objectKey,
                    'mime_type' => $mimeType,
                ],

                'upload' => [
                    'method' => 'PUT',
                    'url' => (string) $signedRequest->getUri(),
                    'headers' => [
                        'Content-Type' => $mimeType,
                    ],
                    'expires_in' => 3600,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Gagal membuat URL upload sementara.',
            ], 500);
        }
    }

    private function resolveProvider(?string $slug): ?StorageProvider
    {
        $query = StorageProvider::query()->active();

        if (filled($slug) && $slug !== 'auto') {
            return $query
                ->where('slug', $slug)
                ->first();
        }

        return (clone $query)
            ->where('is_default', true)
            ->first()
            ?? $query->byPriority()->get()
                ->first(fn (StorageProvider $provider) =>
                    $provider->isUsable()
                    && $provider->driver->isS3Compatible()
                    && $provider->driver->supportsLargeFiles()
                );
    }

    private function makeStoredFilename(string $filename): string
    {
        $filename = basename($filename);

        $extension = strtolower(
            pathinfo($filename, PATHINFO_EXTENSION)
        );

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        $stem = Str::slug($stem);

        if ($stem === '') {
            $stem = 'video-'.Str::lower(Str::random(12));
        }

        $stem = Str::limit($stem, 120, '');

        return $extension !== ''
            ? $stem.'.'.$extension
            : $stem;
    }

    private function authorized(Request $request): bool
    {
        $configuredToken = (string) config(
            'services.video_worker.token'
        );

        return $configuredToken !== ''
            && hash_equals(
                $configuredToken,
                (string) $request->bearerToken()
            );
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthorized.',
        ], 401);
    }
}