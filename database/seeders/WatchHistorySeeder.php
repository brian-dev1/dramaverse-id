<?php

namespace Database\Seeders;

use App\Models\Episode;
use App\Models\User;
use App\Models\WatchHistory;
use Illuminate\Database\Seeder;

/**
 * Mengisi "Lanjutkan Menonton" untuk pengguna contoh.
 */
class WatchHistorySeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('is_admin', false)->get();

        if ($users->isEmpty()) {
            return;
        }

        $episodes = Episode::query()
            ->with('drama:id')
            ->inRandomOrder()
            ->take(15)
            ->get();

        foreach ($users as $user) {
            foreach ($episodes->random(min(5, $episodes->count())) as $episode) {
                $duration = max($episode->duration, 1);
                $progress = random_int((int) ($duration * 0.1), (int) ($duration * 0.9));

                WatchHistory::updateOrCreate(
                    ['user_id' => $user->id, 'episode_id' => $episode->id],
                    [
                        'drama_id'        => $episode->drama_id,
                        'progress'        => $progress,
                        'completed'       => false,
                        'last_watched_at' => now()->subHours(random_int(1, 240)),
                    ]
                );
            }
        }
    }
}
