<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureDramas();
        $this->ensureEpisodes();
        $this->ensureGenres();
        $this->ensureCountries();
        $this->ensureBanners();
        $this->ensureUploadJobs();
    }

    public function down(): void
    {
        //
    }

    private function ensureDramas(): void
    {
        if (! Schema::hasTable('dramas')) {
            return;
        }

        $this->addMissing('dramas', [
            'original_title' => fn (Blueprint $table) => $table->string('original_title')->nullable(),
            'synopsis' => fn (Blueprint $table) => $table->text('synopsis')->nullable(),
            'poster' => fn (Blueprint $table) => $table->string('poster')->nullable(),
            'cover' => fn (Blueprint $table) => $table->string('cover')->nullable(),
            'trailer_url' => fn (Blueprint $table) => $table->string('trailer_url')->nullable(),
            'gradient' => fn (Blueprint $table) => $table->string('gradient', 8)->default('g1'),
            'country_id' => fn (Blueprint $table) => $table->unsignedBigInteger('country_id')->nullable()->index(),
            'release_year' => fn (Blueprint $table) => $table->year('release_year')->nullable(),
            'total_episode' => fn (Blueprint $table) => $table->unsignedSmallInteger('total_episode')->default(0),
            'duration' => fn (Blueprint $table) => $table->unsignedSmallInteger('duration')->nullable(),
            'status' => fn (Blueprint $table) => $table->string('status')->default('ongoing')->index(),
            'rating' => fn (Blueprint $table) => $table->decimal('rating', 3, 1)->default(0),
            'views' => fn (Blueprint $table) => $table->unsignedBigInteger('views')->default(0),
            'is_vip' => fn (Blueprint $table) => $table->boolean('is_vip')->default(false)->index(),
            'is_featured' => fn (Blueprint $table) => $table->boolean('is_featured')->default(false)->index(),
            'is_trending' => fn (Blueprint $table) => $table->boolean('is_trending')->default(false)->index(),
            'trending_score' => fn (Blueprint $table) => $table->unsignedInteger('trending_score')->default(0),
            'published_at' => fn (Blueprint $table) => $table->timestamp('published_at')->nullable()->index(),
            'deleted_at' => fn (Blueprint $table) => $table->softDeletes(),
        ]);
    }

    private function ensureEpisodes(): void
    {
        if (! Schema::hasTable('episodes')) {
            return;
        }

        $this->addMissing('episodes', [
            'slug' => fn (Blueprint $table) => $table->string('slug')->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'video_url' => fn (Blueprint $table) => $table->string('video_url')->nullable(),
            'embed_url' => fn (Blueprint $table) => $table->string('embed_url')->nullable(),
            'thumbnail' => fn (Blueprint $table) => $table->string('thumbnail')->nullable(),
            'duration' => fn (Blueprint $table) => $table->unsignedInteger('duration')->default(0),
            'is_vip' => fn (Blueprint $table) => $table->boolean('is_vip')->default(false)->index(),
            'views' => fn (Blueprint $table) => $table->unsignedBigInteger('views')->default(0),
            'air_date' => fn (Blueprint $table) => $table->timestamp('air_date')->nullable()->index(),
            'status' => fn (Blueprint $table) => $table->string('status')->default('draft'),
            'published_at' => fn (Blueprint $table) => $table->timestamp('published_at')->nullable(),
            'expired_at' => fn (Blueprint $table) => $table->timestamp('expired_at')->nullable(),
        ]);
    }

    private function ensureGenres(): void
    {
        if (! Schema::hasTable('genres')) {
            return;
        }

        if (! Schema::hasColumn('genres', 'icon')) {
            Schema::table('genres', function (Blueprint $table) {
                $table->string('icon', 32)->nullable();
            });
        }

        if (! Schema::hasColumn('genres', 'color')) {
            Schema::table('genres', function (Blueprint $table) {
                $table->string('color', 7)->nullable();
            });
        }
    }

    private function ensureCountries(): void
    {
        if (! Schema::hasTable('countries')) {
            return;
        }

        if (! Schema::hasColumn('countries', 'description')) {
            Schema::table('countries', function (Blueprint $table) {
                $table->string('description')->nullable();
            });
        }
    }

    private function ensureBanners(): void
    {
        if (! Schema::hasTable('banners')) {
            return;
        }

        $this->addMissing('banners', [
            'subtitle' => fn (Blueprint $table) => $table->string('subtitle')->nullable(),
            'image' => fn (Blueprint $table) => $table->string('image')->nullable(),
            'link' => fn (Blueprint $table) => $table->string('link')->nullable(),
            'button_text' => fn (Blueprint $table) => $table->string('button_text')->nullable(),
            'position' => fn (Blueprint $table) => $table->string('position')->default('hero')->index(),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0)->index(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true)->index(),
            'start_at' => fn (Blueprint $table) => $table->timestamp('start_at')->nullable(),
            'end_at' => fn (Blueprint $table) => $table->timestamp('end_at')->nullable(),
        ]);
    }

    private function ensureUploadJobs(): void
    {
        if (! Schema::hasTable('upload_jobs')) {
            return;
        }

        $this->addMissing('upload_jobs', [
            'batch_uuid' => fn (Blueprint $table) => $table->uuid('batch_uuid')->nullable()->index(),
            'drama_id' => fn (Blueprint $table) => $table->unsignedBigInteger('drama_id')->nullable()->index(),
            'asset_type' => fn (Blueprint $table) => $table->string('asset_type', 32)->nullable(),
            'drama_asset_id' => fn (Blueprint $table) => $table->unsignedBigInteger('drama_asset_id')->nullable()->index(),
        ]);
    }

    private function addMissing(string $tableName, array $columns): void
    {
        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn($tableName, $column)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }
};
