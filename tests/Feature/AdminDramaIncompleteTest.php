<?php

namespace Tests\Feature;

use App\Models\Drama;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDramaIncompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_melihat_nomor_part_yang_hilang_dan_tanpa_video(): void
    {
        $admin = User::factory()->admin()->create();

        $incomplete = Drama::create([
            'title' => 'Drama Belum Lengkap',
            'slug' => 'drama-belum-lengkap',
            'total_episode' => 4,
        ]);

        Episode::create([
            'drama_id' => $incomplete->id,
            'episode_number' => 1,
            'video_url' => 'https://cdn.test/part-1.mp4',
        ]);
        Episode::create([
            'drama_id' => $incomplete->id,
            'episode_number' => 3,
        ]);
        Episode::create([
            'drama_id' => $incomplete->id,
            'episode_number' => 4,
            'embed_url' => 'https://player.test/part-4',
        ]);

        $complete = Drama::create([
            'title' => 'Drama Sudah Lengkap',
            'slug' => 'drama-sudah-lengkap',
            'total_episode' => 1,
        ]);
        Episode::create([
            'drama_id' => $complete->id,
            'episode_number' => 1,
            'video_url' => 'https://cdn.test/lengkap.mp4',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.drama.incomplete'));

        $response->assertOk()
            ->assertSee('Drama Belum Lengkap')
            ->assertSee('Part 2')
            ->assertSee('Part 3')
            ->assertDontSee('Drama Sudah Lengkap');
    }
}
