<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tagihan atas satu paket membership.
 *
 * Nama dan durasi paket disalin ke sini saat invoice dibuat. Harga paket bisa
 * berubah dan paketnya bisa dihapus; invoice lama harus tetap menunjukkan apa
 * yang benar-benar dibeli, bukan keadaan paket hari ini.
 */
class Invoice extends Model
{
    protected $fillable = [
        'number',
        'user_id',
        'membership_plan_id',
        'plan_name',
        'plan_duration',
        'subtotal',
        'fee',
        'total',
        'paid_amount',
        'currency',
        'status',
        'due_at',
        'paid_at',
        'cancelled_at',
        'note',
    ];

    protected $casts = [
        'plan_duration' => 'integer',
        'subtotal'      => 'decimal:2',
        'fee'           => 'decimal:2',
        'total'         => 'decimal:2',
        'paid_amount'   => 'decimal:2',
        'status'        => PaymentStatus::class,
        'due_at'        => 'datetime',
        'paid_at'       => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** Percobaan pembayaran terakhir. */
    public function latestTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class)->latestOfMany();
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::PENDING->value);
    }

    /** Sudah lewat jatuh tempo dan belum dibayar. */
    public function isOverdue(): bool
    {
        return $this->status === PaymentStatus::PENDING
            && $this->due_at !== null
            && now()->gt($this->due_at);
    }

    public function isPayable(): bool
    {
        return $this->status === PaymentStatus::PENDING && ! $this->isOverdue();
    }

    /*
    |--------------------------------------------------------------------------
    | Pembayaran bertahap
    |--------------------------------------------------------------------------
    */

    /** Sisa yang masih harus dibayar. Nol berarti sudah cukup. */
    public function outstanding(): float
    {
        return max(0, round((float) $this->total - (float) $this->paid_amount, 2));
    }

    /**
     * Sudah terkumpul cukup untuk dianggap lunas.
     *
     * Toleransi satu rupiah untuk pembulatan biaya layanan di sisi provider —
     * angka yang sama dipakai penjagaan nominal di PaymentCallbackService.
     */
    public function isSettled(): bool
    {
        return (float) $this->paid_amount + 1 >= (float) $this->total;
    }

    /** Berapa persen sudah terbayar, untuk ditampilkan. */
    public function paidPercent(): int
    {
        $total = (float) $this->total;

        return $total <= 0 ? 100 : min(100, (int) floor((float) $this->paid_amount / $total * 100));
    }
}
