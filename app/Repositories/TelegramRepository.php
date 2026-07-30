<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\TelegramRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lihat TelegramRepositoryInterface untuk alasan bentuknya.
 *
 * Tidak ada satu pun panggilan HTTP di sini, dan tidak boleh ada.
 */
class TelegramRepository implements TelegramRepositoryInterface
{
    /**
     * Ambang "tidak aktif", dalam hari.
     *
     * Satu tempat saja: segmen `active` dan `inactive` harus selalu jadi
     * pasangan yang saling melengkapi. Saat dua angka ini ditulis terpisah,
     * mengubah salah satunya membuat sebagian pengguna tidak masuk segmen
     * mana pun — dan tidak ada yang menyadarinya karena jumlahnya cuma
     * berkurang sedikit.
     */
    protected const DORMANT_DAYS = 30;

    public function audiences(): array
    {
        return [
            'all'      => 'Semua pengguna Telegram',
            'active'   => 'Aktif dalam '.self::DORMANT_DAYS.' hari terakhir',
            'vip'      => 'Anggota berlangganan aktif',
            'inactive' => 'Belum aktif lebih dari '.self::DORMANT_DAYS.' hari',
        ];
    }

    public function audienceQuery(string $key): Builder
    {
        $query = User::query()
            ->where('is_admin', false)
            ->whereNotNull('telegram_id');

        $batas = now()->subDays(self::DORMANT_DAYS);

        return match ($key) {

            'active' => $query->where('last_seen_at', '>=', $batas),

            // Belum pernah terlihat sama sekali ikut hitungan "tidak aktif".
            // Tanpa cabang whereNull, pengguna yang mendaftar lalu tidak
            // pernah kembali tidak masuk segmen mana pun.
            'inactive' => $query->where(fn ($q) => $q
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $batas)),

            'vip' => $query->whereHas('subscriptions', fn ($q) => $q
                ->where('status', 'active')
                ->where(fn ($w) => $w
                    ->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now()))),

            default => $query,
        };
    }

    public function recipients(string $key): Collection
    {
        return $this->audienceQuery($key)
            ->where('is_banned', false)
            ->pluck('telegram_id', 'id');
    }

    public function counts(): array
    {
        $hasil = [];

        foreach (array_keys($this->audiences()) as $key) {
            $hasil[$key] = $this->audienceQuery($key)->count();
        }

        return $hasil;
    }

    public function stats(): array
    {
        $base = fn (): Builder => User::query()->whereNotNull('telegram_id');

        return [
            'total'  => $base()->count(),
            'active' => $base()->where('is_active', true)->count(),
            'banned' => $base()->where('is_banned', true)->count(),
            'today'  => $base()->whereDate('created_at', today())->count(),
        ];
    }

    public function findByTelegramId(int|string $telegramId): ?User
    {
        return User::query()
            ->where('telegram_id', $telegramId)
            ->first();
    }

    public function queueHealth(): array
    {
        $connection = (string) config('queue.default');

        $queue = (string) config("queue.connections.{$connection}.queue", 'default');

        $pending = null;

        $failed = null;

        // Hanya driver database yang jumlahnya bisa dibaca dari sini. Redis
        // dan SQS punya cara sendiri, dan menebaknya lebih buruk daripada
        // menampilkan tanda hubung.
        if ($connection === 'database') {

            try {
                $pending = DB::table('jobs')->where('queue', $queue)->count();

                $failed = DB::table('failed_jobs')->count();
            } catch (Throwable) {
                // Tabel antrean belum dibuat. Halaman admin tidak boleh mati
                // karena panel keterangan tambahan.
            }
        }

        return [
            'connection' => $connection,
            'queue'      => $queue,
            'pending'    => $pending,
            'failed'     => $failed,
        ];
    }

    public function deactivateByTelegramId(int|string $telegramId): int
    {
        return User::query()
            ->where('telegram_id', $telegramId)
            ->update(['is_active' => false]);
    }
}
