<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UploadMediaJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;
    use InteractsWithQueue;

    public function __construct()
    {
    }

    public function handle(): void
    {
        /**
         * Upload media besar
         */

    }
}