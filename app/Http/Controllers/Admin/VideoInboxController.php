<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Models\VideoInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VideoInboxController extends Controller
{
    public function index(): View
    {
        $videos = VideoInbox::query()
            ->with([
                'provider:id,name,slug,bucket',
                'episode:id,drama_id,episode_number,title',
                'episode.drama:id,title,slug',
            ])
            ->latest('uploaded_at')
            ->paginate(20);

        $dramas = Drama::query()
            ->select('id', 'title')
            ->orderBy('title')
            ->get();

        return view('web.pages.admin.video-inbox', compact(
            'videos',
            'dramas'
        ));
    }

    public function assign(
        Request $request,
        VideoInbox $videoInbox
    ): RedirectResponse {
        $data = $request->validate([
            'episode_id' => ['required', 'integer', 'exists:episodes,id'],
        ]);

        if (! $videoInbox->isAvailable()) {
            return back()->with(
                'error',
                'Video ini sudah dipasang ke episode atau tidak lagi tersedia.'
            );
        }

        if (blank($videoInbox->checksum)) {
            return back()->with(
                'error',
                'Video belum memiliki checksum SHA-256 dan belum dapat dipasang.'
            );
        }

        $episode = Episode::query()->findOrFail($data['episode_id']);

        $provider = $videoInbox->provider;

        if (! $provider) {
            return back()->with(
                'error',
                'Storage provider video tidak ditemukan.'
            );
        }

        $objectKey = ltrim($videoInbox->object_key, '/');

        $directory = pathinfo($objectKey, PATHINFO_DIRNAME);

        if ($directory === '.') {
            $directory = null;
        }

        $storedFilename = pathinfo($objectKey, PATHINFO_BASENAME);

        $extension = pathinfo(
            $videoInbox->original_filename,
            PATHINFO_EXTENSION
        );

        DB::transaction(function () use (
            $videoInbox,
            $episode,
            $provider,
            $objectKey,
            $directory,
            $storedFilename,
            $extension
        ) {
            EpisodeVideo::updateOrCreate(
                [
                    'episode_id' => $episode->id,
                ],
                [
                    'storage_provider_id' => $provider->id,
                    'uploaded_by' => Auth::id(),

                    'disk' => $provider->slug,
                    'bucket' => $provider->bucket,

                    'object_key' => $objectKey,
                    'directory' => $directory,

                    'original_filename' => $videoInbox->original_filename,
                    'stored_filename' => $storedFilename,
                    'extension' => $extension ?: null,

                    'mime_type' => $videoInbox->mime_type ?: 'video/mp4',
                    'size' => $videoInbox->size,
                    'checksum' => $videoInbox->checksum,

                    'public_url' => $videoInbox->public_url,
                    'uploaded_at' => $videoInbox->uploaded_at ?? now(),
                ]
            );

            $videoInbox->update([
                'status' => 'assigned',
                'episode_id' => $episode->id,
                'assigned_at' => now(),
            ]);
        });

        return back()->with(
            'success',
            'Video berhasil dipasang ke episode.'
        );
    }
}