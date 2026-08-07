<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu video yang pernah dikirim bot ke sebuah chat.
 *
 * Lihat migrasi `create_telegram_deliveries_table` untuk alasan tabel ini ada.
 */
class TelegramDelivery extends Model
{
    use HasFactory;

    /** Menunggu giliran dihapus, atau memang dibiarkan. */
    public const PENDING = 'pending';

    /** Sudah benar-benar hilang dari chat pengguna. */
    public const DELETED = 'deleted';

    /** Lewat 48 jam — Telegram menolak, dan akan selalu menolak. */
    public const TOO_OLD = 'too_old';

    /** Gagal karena sebab lain: bot diblokir, chat dihapus, dan sejenisnya. */
    public const FAILED = 'failed';

    /** Sengaja tidak dihapus (mis. episode gratis). */
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'episode_id',
        'chat_id',
        'message_id',
        'is_premium',
        'sent_at',
        'delete_after',
        'delete_status',
        'deleted_at',
        'delete_error',
    ];

    protected $casts = [
        'chat_id'      => 'integer',
        'message_id'   => 'integer',
        'is_premium'   => 'boolean',
        'sent_at'      => 'datetime',
        'delete_after' => 'datetime',
        'deleted_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /**
     * Masih dalam jendela 48 jam milik Telegram?
     *
     * Diberi selisih lima menit supaya baris yang tepat di ambang tidak
     * dicoba dihapus lalu gagal — kegagalan yang sudah pasti tidak perlu
     * memakan satu panggilan API.
     */
    public function bisaDihapus(): bool
    {
        return $this->sent_at !== null
            && $this->sent_at->gt(now()->subHours(48)->addMinutes(5));
    }

    public function statusLabel(): string
    {
        return match ($this->delete_status) {
            self::DELETED => 'Terhapus',
            self::TOO_OLD => 'Lewat 48 jam',
            self::FAILED  => 'Gagal dihapus',
            self::SKIPPED => 'Dilewati',
            default       => 'Masih di chat',
        };
    }
}
