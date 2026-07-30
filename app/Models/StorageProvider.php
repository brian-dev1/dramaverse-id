<?php

namespace App\Models;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu tujuan penyimpanan berkas.
 *
 * Baris di tabel ini adalah sumber kebenaran konfigurasi disk — bukan
 * config/filesystems.php. Disk dibangun saat dibutuhkan oleh StorageManager
 * dari baris ini, sehingga provider bisa ditambah atau dimatikan tanpa
 * deploy ulang.
 *
 * Kredensial disimpan terenkripsi memakai APP_KEY. Konsekuensinya: kalau
 * APP_KEY diganti, seluruh access_key dan secret_key di tabel ini tidak bisa
 * lagi dibaca dan harus dimasukkan ulang.
 *
 * Memakai soft delete. Penghapusan provider menghilangkan kredensial dan
 * pemetaan ke bucket tempat berkas sungguhan berada — berkasnya sendiri tidak
 * ikut terhapus, sehingga tanpa baris ini aplikasi kehilangan satu-satunya
 * jalan menjangkaunya. Seluruh scope di bawah otomatis hanya melihat baris
 * hidup, jadi StorageManager tidak pernah memilih provider yang sudah dihapus.
 */
class StorageProvider extends Model
{
    use SoftDeletes;

    protected $fillable = [

        'name',

        'slug',

        'driver',

        'bucket',

        'endpoint',

        'region',

        'access_key',

        'secret_key',

        'root',

        'public_url',

        'visibility',

        'use_path_style',

        'options',

        'status',

        'priority',

        'is_default',

        'last_tested_at',

        'last_test_status',

        'last_test_message',

    ];

    protected $casts = [

        'driver' => StorageDriver::class,

        'status' => StorageStatus::class,

        'access_key' => 'encrypted',

        'secret_key' => 'encrypted',

        'use_path_style' => 'boolean',

        'is_default' => 'boolean',

        'priority' => 'integer',

        'options' => 'array',

        'last_tested_at' => 'datetime',

    ];

    /**
     * Kredensial tidak pernah ikut serialisasi. Menjaga agar tidak bocor
     * lewat response JSON, log, atau dd() yang tidak sengaja terkirim.
     */
    protected $hidden = [

        'access_key',

        'secret_key',

    ];

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StorageStatus::ACTIVE->value);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', StorageStatus::INACTIVE->value);
    }

    /**
     * Angka priority lebih kecil dicoba lebih dulu. `id` dipakai sebagai
     * pemecah seri supaya urutannya stabil antar-permintaan.
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderBy('id');
    }

    public function scopeIsDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopeDriver(Builder $query, StorageDriver|string $driver): Builder
    {
        return $query->where(
            'driver',
            $driver instanceof StorageDriver ? $driver->value : $driver
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pemeriksaan
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === StorageStatus::ACTIVE;
    }

    /**
     * Field wajib yang masih kosong. Array kosong berarti provider lengkap.
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach ($this->driver->requiredFields() as $field) {
            if (blank($this->{$field})) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingFields() === [];
    }

    /**
     * Paket composer adapter benar-benar terpasang di vendor/.
     *
     * Tanpa pemeriksaan ini, provider yang kelihatan lengkap di panel admin
     * tetap akan gagal saat disk dibangun, dengan pesan error Flysystem yang
     * tidak memberi petunjuk apa pun.
     */
    public function hasAdapterInstalled(): bool
    {
        $class = $this->driver->adapterClass();

        return $class === null || class_exists($class);
    }

    /**
     * Field yang masih memuat nilai contoh.
     *
     * @return array<string, string>  nama field => penanda yang tertangkap
     */
    public function placeholderFields(): array
    {
        $tokens = (array) config('storage.placeholder_tokens', []);

        if ($tokens === []) {
            return [];
        }

        $fields = [
            'bucket', 'endpoint', 'region', 'root', 'public_url',
            'access_key', 'secret_key',
        ];

        $found = [];

        foreach ($fields as $field) {
            $value = (string) ($this->{$field} ?? '');

            if ($value === '') {
                continue;
            }

            foreach ($tokens as $token) {
                if (stripos($value, (string) $token) !== false) {
                    // Yang dicatat hanya penandanya, bukan nilai lengkapnya.
                    // access_key dan secret_key terdekripsi saat dibaca, dan
                    // isinya tidak boleh ikut masuk pesan galat atau log.
                    $found[$field] = (string) $token;

                    break;
                }
            }
        }

        return $found;
    }

    public function hasPlaceholders(): bool
    {
        return $this->placeholderFields() !== [];
    }

    /**
     * Siap menerima lalu lintas: aktif, lengkap, adapternya ada, dan tidak
     * ada nilai contoh yang tertinggal.
     */
    public function isUsable(): bool
    {
        return $this->isActive()
            && $this->isConfigured()
            && $this->hasAdapterInstalled()
            && ! $this->hasPlaceholders();
    }

    /*
    |--------------------------------------------------------------------------
    | Turunan
    |--------------------------------------------------------------------------
    */

    public function getDriverLabelAttribute(): string
    {
        return $this->driver->label();
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /**
     * Region efektif: isi admin, atau bawaan provider.
     */
    public function effectiveRegion(): ?string
    {
        return $this->region ?: $this->driver->defaultRegion();
    }

    /**
     * Path-style efektif. Sekali admin menyalakannya, pilihan itu dihormati;
     * kalau tidak, provider yang memang mengharuskannya tetap dapat true.
     */
    public function effectivePathStyle(): bool
    {
        return $this->use_path_style || $this->driver->prefersPathStyle();
    }

    /**
     * Endpoint tanpa garis miring di ujung. Beberapa SDK menggabungkan
     * endpoint dan key mentah-mentah sehingga "//" ikut terkirim.
     */
    public function normalizedEndpoint(): ?string
    {
        return $this->endpoint ? rtrim($this->endpoint, '/') : null;
    }

    public function normalizedPublicUrl(): ?string
    {
        return $this->public_url ? rtrim($this->public_url, '/') : null;
    }
}
