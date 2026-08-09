<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Drama extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'original_title',
        'synopsis',
        'poster',
        'cover',
        'trailer_url',
        'gradient',
        'country_id',
        'total_episode',
        'status',
        'views',
        'is_vip',
        'is_featured',
        'is_trending',
        'published_at',
    ];

    protected $casts = [
        'total_episode'  => 'integer',
        'views'          => 'integer',
        'is_vip'         => 'boolean',
        'is_featured'    => 'boolean',
        'is_trending'    => 'boolean',
        'published_at'   => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** Satu drama punya banyak genre (pivot drama_genre). */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'drama_genre');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('episode_number');
    }

    public function watchHistories(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    /**
     * Aset berkas: poster, cover, banner, galeri, subtitle, dan lainnya.
     *
     * Kolom `poster` dan `cover` yang sudah ada TIDAK digantikan relasi ini.
     * Keduanya berdampingan: kolom lama menyimpan path di disk `public`
     * (ditulis Admin\MediaService), relasi ini menyimpan berkas yang benar-benar
     * diunggah ke storage provider lewat Storage Engine. Pemindahan yang lama
     * ke yang baru adalah pekerjaan tersendiri, dicatat di STATUS.md.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(DramaAsset::class)->ordered();
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeTrending(Builder $query): Builder
    {
        return $query->where('is_trending', true)
            ->orderByDesc('published_at');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeVip(Builder $query): Builder
    {
        return $query->where('is_vip', true);
    }

    public function scopeLatestRelease(Builder $query): Builder
    {
        return $query->orderByDesc('published_at');
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->orderByDesc('views');
    }

    /*
    |--------------------------------------------------------------------------
    | Aksesor
    |--------------------------------------------------------------------------
    */

    /** URL poster, atau null bila memakai gradien fallback. */
    public function getPosterUrlAttribute(): ?string
    {
        return $this->poster ? asset('storage/'.$this->poster) : null;
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover ? asset('storage/'.$this->cover) : null;
    }
}
