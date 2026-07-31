<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu percobaan pembayaran atas satu invoice.
 *
 * Beberapa baris per invoice adalah keadaan normal: pengguna yang gagal bayar
 * lalu mencoba lagi dengan provider berbeda menghasilkan baris kedua, dan
 * riwayat percobaan pertamanya harus tetap ada.
 */
class PaymentTransaction extends Model
{
    protected $fillable = [
        'invoice_id',
        'payment_provider_id',
        'reference',
        'external_id',
        'amount',
        'currency',
        'status',
        'refund_status',
        'method',
        'checkout_url',
        'request_payload',
        'response_payload',
        'signature',
        'paid_at',
        'verified_at',
        'expires_at',
        'verify_attempts',
        'last_error',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'status'           => PaymentStatus::class,
        'refund_status'    => RefundStatus::class,
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'paid_at'          => 'datetime',
        'verified_at'      => 'datetime',
        'expires_at'       => 'datetime',
        'verify_attempts'  => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    /**
     * Masih pantas ditanyakan lagi ke provider.
     *
     * Yang sudah final tidak perlu diverifikasi: jawabannya tidak akan
     * berubah, dan menanyakannya berulang hanya membebani kuota API provider.
     */
    public function needsVerification(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }
}
