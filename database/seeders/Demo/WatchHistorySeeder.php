<?php

namespace Database\Seeders\Demo;

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
                // `progress` disimpan sebagai persen (0-100).
                $progress = random_int(10, 90);

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
