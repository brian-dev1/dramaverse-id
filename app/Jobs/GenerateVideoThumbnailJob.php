<?php

namespace App\Jobs;

use App\Models\Episode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateVideoThumbnailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected Episode $episode
    ) {
    }

    public function handle(): void
    {
        /**
         * TODO
         *
         * Generate thumbnail menggunakan FFMpeg
         * kemudian simpan path thumbnail
         */

    }
}