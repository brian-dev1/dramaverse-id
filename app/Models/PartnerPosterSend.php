<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kiriman poster ke grup partner.
 *
 * Ditulis baik saat berhasil maupun gagal. Kegagalan yang tidak tercatat
 * berarti admin menekan Kirim Semua, melihat sebagian poster tidak muncul di
 * grup, dan tidak punya satu pun tempat untuk mencari tahu sebabnya.
 */
class PartnerPosterSend extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'drama_id',
        'chat_id',
        'thread_id',
        'message_id',
        'status',
        'error',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'drama_id'   => 'integer',
        'thread_id'  => 'integer',
        'message_id' => 'integer',
        'sent_by'    => 'integer',
        'sent_at'    => 'datetime',
    ];

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function berhasil(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SENT   => 'Terkirim',
            self::STATUS_FAILED => 'Gagal',
            default             => 'Diantre',
        };
    }

    /**
     * Kelas badge dipetakan di sini, bukan di Blade.
     *
     * Alasannya sama seperti di model lain proyek ini: status baru tidak
     * boleh meninggalkan badge tanpa warna di halaman yang kebetulan tidak
     * ikut disunting.
     */
    public function statusBadge(): string
    {
        return match ($this->status) {
            self::STATUS_SENT   => 'badge-on',
            self::STATUS_FAILED => 'badge-off',
            default             => 'badge-pending',
        };
    }
}
