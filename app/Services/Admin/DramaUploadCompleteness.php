<?php

namespace App\Services\Admin;

use App\Models\Drama;
use App\Models\Episode;

/**
 * Menentukan part mana yang belum siap di sebuah drama.
 *
 * Sumber video bisa berasal dari unggahan Storage Engine (`episode_videos`)
 * maupun URL yang ditempel langsung di episode. Keduanya dianggap sudah
 * terisi agar panel tidak memberi peringatan palsu.
 */
class DramaUploadCompleteness
{
    /**
     * @return array{
     *     expected: int,
     *     missing_episodes: array<int,int>,
     *     missing_videos: array<int,int>
     * }
     */
    public function inspect(Drama $drama): array
    {
        $episodes = $drama->episodes->keyBy('episode_number');
        $expected = max(
            (int) $drama->total_episode,
            (int) ($episodes->keys()->max() ?? 0)
        );

        $missingEpisodes = [];
        $missingVideos = [];

        for ($number = 1; $number <= $expected; $number++) {
            /** @var Episode|null $episode */
            $episode = $episodes->get($number);

            if ($episode === null) {
                $missingEpisodes[] = $number;
                continue;
            }

            if (! $this->hasVideoSource($episode)) {
                $missingVideos[] = $number;
            }
        }

        return [
            'expected'         => $expected,
            'missing_episodes' => $missingEpisodes,
            'missing_videos'   => $missingVideos,
        ];
    }

    /** @param array<int,int> $numbers */
    public function ranges(array $numbers): string
    {
        if ($numbers === []) {
            return '';
        }

        sort($numbers, SORT_NUMERIC);

        $ranges = [];
        $start = $previous = array_shift($numbers);

        foreach ($numbers as $number) {
            if ($number === $previous + 1) {
                $previous = $number;
                continue;
            }

            $ranges[] = $this->rangeLabel($start, $previous);
            $start = $previous = $number;
        }

        $ranges[] = $this->rangeLabel($start, $previous);

        return implode(', ', $ranges);
    }

    private function hasVideoSource(Episode $episode): bool
    {
        return filled($episode->video_url)
            || filled($episode->embed_url)
            || ($episode->relationLoaded('video') && $episode->video !== null);
    }

    private function rangeLabel(int $start, int $end): string
    {
        return $start === $end ? (string) $start : $start.'–'.$end;
    }
}
