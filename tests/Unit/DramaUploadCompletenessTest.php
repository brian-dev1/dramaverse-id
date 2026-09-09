<?php

namespace Tests\Unit;

use App\Models\Drama;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Services\Admin\DramaUploadCompleteness;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DramaUploadCompletenessTest extends TestCase
{
    public function test_membedakan_part_yang_hilang_dan_part_tanpa_video(): void
    {
        $drama = new Drama(['total_episode' => 5]);

        $part1 = new Episode(['episode_number' => 1, 'video_url' => 'https://cdn.test/1.mp4']);
        $part2 = new Episode(['episode_number' => 2]);
        $part2->setRelation('video', new EpisodeVideo());
        $part4 = new Episode(['episode_number' => 4]);
        $part4->setRelation('video', null);
        $part5 = new Episode(['episode_number' => 5, 'embed_url' => 'https://player.test/5']);

        $drama->setRelation('episodes', new Collection([$part1, $part2, $part4, $part5]));

        $result = (new DramaUploadCompleteness())->inspect($drama);

        self::assertSame([3], $result['missing_episodes']);
        self::assertSame([4], $result['missing_videos']);
    }

    public function test_meringkas_nomor_berurutan_agar_daftar_tetap_ringkas(): void
    {
        $service = new DramaUploadCompleteness();

        self::assertSame('1–3, 5, 8–9', $service->ranges([9, 1, 2, 8, 5, 3]));
        self::assertSame('', $service->ranges([]));
    }
}
