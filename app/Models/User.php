<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'telegram_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'telegram_language',
        'telegram_photo_url',
        'email',
        'password',
        'is_admin',
        'is_active',
        'is_banned',
        'last_login_at',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_admin'          => 'boolean',
            'is_active'         => 'boolean',
            'is_banned'         => 'boolean',
            'last_login_at'     => 'datetime',
            'last_seen_at'      => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function watchHistories(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /** Nama tampilan: pakai nama Telegram bila tersedia. */
    public function getDisplayNameAttribute(): string
    {
        $full = trim(($this->telegram_first_name ?? '').' '.($this->telegram_last_name ?? ''));

        return $full !== '' ? $full : $this->name;
    }

    /** Inisial untuk avatar. */
    public function getInitialAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->display_name, 0, 1));
    }
}
