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
        'proof_path',
        'proof_file_id',
        'proof_uploaded_at',
        'proof_note',
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
        'paid_at'           => 'datetime',
        'verified_at'       => 'datetime',
        'expires_at'        => 'datetime',
        'proof_uploaded_at' => 'datetime',
        'verify_attempts'   => 'integer',
    ];

    /** Pengguna sudah mengunggah bukti bayar. */
    public function hasProof(): bool
    {
        return filled($this->proof_path) || filled($this->proof_file_id);
    }

    /**
     * URL bukti bayar yang bisa dibuka admin, atau null.
     *
     * ## Kenapa route, bukan `asset('storage/...')`
     *
     * Dua sebab, dan yang pertama adalah bug yang membuat bukti tidak pernah
     * terlihat di panel: URL statis hanya bekerja bila symlink `public/storage`
     * ada dan folder tujuannya bisa ditulis php-fpm. Salah satu saja tidak
     * terpenuhi, hasilnya bingkai gambar kosong tanpa satu pun galat.
     *
     * Yang kedua, bukti bayar memuat nomor rekening dan saldo. Berkas di bawah
     * `public/` bisa dibuka siapa saja yang tahu URL-nya, tanpa login. Route
     * ini melewati middleware admin dan pemeriksaan izin seperti halaman
     * lainnya.
     *
     * Syaratnya `hasProof()`, bukan `proof_path`: bukti yang salinan lokalnya
     * gagal ditulis masih punya `file_id`, dan route-nya akan menariknya ulang
     * dari Telegram saat dibuka. Menyembunyikan tautannya karena path-nya
     * kosong justru membuang satu-satunya salinan yang tersisa.
     */
    public function proofUrl(): ?string
    {
        return $this->hasProof() && $this->exists
            ? route('admin.manual-approval.proof', $this->id)
            : null;
    }

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
