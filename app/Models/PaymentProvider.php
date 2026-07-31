<?php

namespace App\Models;

use App\Enums\PaymentDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu provider pembayaran yang terpasang.
 *
 * `credentials` di-cast `encrypted:array` — nilainya terenkripsi di kolom dan
 * hanya terbaca sebagai array di dalam aplikasi. Server key gateway setara
 * kunci brankas: siapa pun yang memilikinya bisa membuat transaksi atas nama
 * kita.
 */
class PaymentProvider extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'credentials',
        'mode',
        'is_active',
        'is_default',
        'sort_order',
        'fee_percent',
        'fee_flat',
        'instruction',
    ];

    protected $casts = [
        'driver'      => PaymentDriver::class,
        'credentials' => 'encrypted:array',
        'is_active'   => 'boolean',
        'is_default'  => 'boolean',
        'sort_order'  => 'integer',
        'fee_percent' => 'decimal:2',
        'fee_flat'    => 'decimal:2',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Satu nilai kredensial. */
    public function credential(string $key): ?string
    {
        $nilai = ($this->credentials ?? [])[$key] ?? null;

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /** Field wajib yang masih kosong. */
    public function missingFields(): array
    {
        $kurang = [];

        foreach ($this->driver->requiredFields() as $field => $label) {
            if ($this->credential($field) === null) {
                $kurang[] = $field;
            }
        }

        return $kurang;
    }

    /**
     * Siap menerima pembayaran.
     *
     * Aktif saja tidak cukup: driver yang belum selesai dan kredensial yang
     * belum lengkap sama-sama menghasilkan kegagalan di tengah checkout,
     * yaitu tempat paling buruk untuk gagal.
     */
    public function isUsable(): bool
    {
        return $this->is_active
            && $this->driver->isImplemented()
            && $this->missingFields() === [];
    }

    /** Alasan tidak bisa dipakai, atau null bila bisa. */
    public function blocker(): ?string
    {
        if (! $this->driver->isImplemented()) {
            return "Driver {$this->driver->label()} masih kerangka — alur callback dan "
                .'tanda tangannya belum pernah diuji dengan akun sungguhan.';
        }

        if ($kurang = $this->missingFields()) {
            return 'Kredensial belum lengkap: '.implode(', ', $kurang).'.';
        }

        if (! $this->is_active) {
            return 'Provider berstatus nonaktif.';
        }

        return null;
    }

    /** Biaya layanan untuk nominal tertentu. */
    public function feeFor(float $subtotal): float
    {
        return round($subtotal * ((float) $this->fee_percent / 100) + (float) $this->fee_flat, 2);
    }

    public function isSandbox(): bool
    {
        return $this->mode !== 'live';
    }
}
