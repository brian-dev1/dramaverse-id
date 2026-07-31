<?php

namespace App\Services\Membership;

use App\Support\Concerns\LogsPaymentEvents;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\MembershipRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aturan membership, terpisah dari cara pembayarannya.
 *
 * ## Pemisahan yang jadi inti Phase 10
 *
 * Kelas ini **tidak tahu** apa itu gateway, callback, atau tanda tangan. Yang
 * diketahuinya: sebuah invoice sudah lunas, dan karenanya seseorang berhak
 * menonton sampai tanggal tertentu.
 *
 * Karena itu menambah Stripe atau PayPal tidak menyentuh satu baris pun di
 * sini. Sebaliknya, mengubah aturan perpanjangan tidak menyentuh satu driver
 * pun.
 *
 * ## Perpanjangan menumpuk, tidak menimpa
 *
 * Pengguna yang membeli lagi sementara langganannya masih berjalan mendapat
 * masa aktif yang DITAMBAHKAN ke sisa yang ada, bukan dihitung ulang dari
 * hari ini. Menghitung ulang berarti orang yang memperpanjang lebih awal
 * kehilangan sisa hari yang sudah dibayarnya — dan itu keluhan yang benar,
 * bukan salah paham.
 */
class MembershipService
{
    use LogsPaymentEvents;

    /** Cache status per pengguna. Dibuang eksplisit setiap kali berubah. */
    private const CACHE = 'membership:status:';

    public function __construct(
        protected MembershipRepositoryInterface $repository
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Pembacaan
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int,MembershipPlan> */
    public function plans(): Collection
    {
        return $this->repository->plans();
    }

    /** Langganan aktif milik pengguna, atau null. */
    public function active(User $user): ?Subscription
    {
        return $this->repository->active($user);
    }

    /**
     * Ringkasan keadaan membership, di-cache.
     *
     * Dibaca di setiap halaman berbayar dan di setiap permintaan menonton
     * lewat bot. TTL-nya pendek dengan sengaja: yang paling buruk dari cache
     * ini adalah membership yang sudah habis masih terbaca aktif, dan lima
     * menit adalah selisih yang bisa diterima. Setiap perubahan tetap
     * membuang cache-nya secara eksplisit.
     *
     * @return array{status:string, label:string, expires_at:?string, plan:?string}
     */
    public function status(User $user): array
    {
        return Cache::remember(
            self::CACHE.$user->id,
            (int) config('payment.membership_cache_ttl', 300),
            function () use ($user) {

                $aktif = $this->active($user);

                if ($aktif !== null) {
                    return [
                        'status'     => 'premium',
                        'label'      => 'Premium aktif',
                        'expires_at' => $aktif->expired_at?->toIso8601String(),
                        'plan'       => $aktif->plan?->name,
                    ];
                }

                // Pernah berlangganan tapi sudah habis dibedakan dari yang
                // belum pernah sama sekali. Memberi tahu orang yang baru saja
                // membayar bahwa ia "pengguna gratis" adalah jawaban yang
                // terasa salah.
                $pernah = $this->repository->lastEnded($user);

                return $pernah !== null
                    ? [
                        'status'     => 'expired',
                        'label'      => 'Kedaluwarsa',
                        'expires_at' => $pernah->expired_at?->toIso8601String(),
                        'plan'       => $pernah->plan?->name,
                    ]
                    : [
                        'status'     => 'free',
                        'label'      => 'Gratis',
                        'expires_at' => null,
                        'plan'       => null,
                    ];
            }
        );
    }

    /** Riwayat langganan, terbaru lebih dulu. */
    public function history(User $user, int $limit = 50): Collection
    {
        return $this->repository->history($user, $limit);
    }

    /*
    |--------------------------------------------------------------------------
    | Perubahan
    |--------------------------------------------------------------------------
    */

    /**
     * Aktifkan membership karena tagihannya lunas.
     *
     * Dipanggil `PaymentCallbackService` di dalam transaction yang sama dengan
     * pelunasan invoice — supaya tidak pernah ada keadaan "sudah bayar tapi
     * belum aktif" yang bertahan lebih dari sepersekian detik.
     *
     * Idempoten: invoice yang langganannya sudah aktif dikembalikan apa
     * adanya. Callback ganda tidak melipatgandakan masa aktif.
     */
    public function activateFromInvoice(Invoice $invoice): ?Subscription
    {
        $langganan = $invoice->subscription()->first();

        if ($langganan === null) {
            return null;
        }

        if ($langganan->status === SubscriptionStatus::ACTIVE->value) {

            $this->log('info', 'membership.already_active', [
                'invoice'      => $invoice->number,
                'subscription' => $langganan->id,
            ]);

            return $langganan;
        }

        $mulai = $this->startFrom($invoice->user_id);

        $langganan->forceFill([
            'status'     => SubscriptionStatus::ACTIVE->value,
            'started_at' => $mulai,
            'expired_at' => $mulai->copy()->addDays(max(1, (int) $invoice->plan_duration)),
        ])->save();

        $this->syncUserFlags($invoice->user_id);

        $this->log('info', 'membership.activated', [
            'invoice'      => $invoice->number,
            'user_id'      => $invoice->user_id,
            'subscription' => $langganan->id,
            'sampai'       => $langganan->expired_at?->toDateString(),
        ]);

        return $langganan;
    }

    /**
     * Aktivasi langsung oleh admin, tanpa pembayaran.
     *
     * Ada karena keadaan nyata memerlukannya: kompensasi gangguan, hadiah,
     * pembayaran yang masuk lewat jalur yang tidak tercatat sistem. Dicatat
     * dengan `source = admin` supaya bisa dibedakan dari yang dibayar.
     */
    public function grant(User $user, MembershipPlan $plan, string $alasan = ''): Subscription
    {
        $mulai = $this->startFrom($user->id);

        $langganan = Subscription::create([
            'user_id'            => $user->id,
            'membership_plan_id' => $plan->id,
            'price'              => $plan->price,
            'started_at'         => $mulai,
            'expired_at'         => $mulai->copy()->addDays(max(1, (int) $plan->duration)),
            'status'             => SubscriptionStatus::ACTIVE->value,
            'source'             => 'admin',
        ]);

        $this->syncUserFlags($user->id);

        $this->log('info', 'membership.granted', [
            'user_id' => $user->id,
            'plan'    => $plan->slug ?? $plan->id,
            'alasan'  => $alasan,
        ]);

        return $langganan;
    }

    /** Batalkan langganan yang sedang berjalan. */
    public function cancel(Subscription $subscription, string $alasan = ''): Subscription
    {
        $subscription->forceFill([
            'status'       => SubscriptionStatus::CANCELLED->value,
            'cancelled_at' => now(),
            'auto_renew'   => false,
        ])->save();

        $this->syncUserFlags($subscription->user_id);

        $this->log('info', 'membership.cancelled', [
            'subscription' => $subscription->id,
            'user_id'      => $subscription->user_id,
            'alasan'       => $alasan,
        ]);

        return $subscription;
    }

    /**
     * Batalkan langganan PENDING milik satu invoice.
     *
     * Dipanggil saat pembayarannya gagal atau kedaluwarsa. Langganan pending
     * yang tertinggal akan muncul di riwayat pengguna selamanya sebagai
     * "menunggu pembayaran" untuk tagihan yang sudah tidak bisa dibayar.
     */
    public function cancelPendingFor(Invoice $invoice): void
    {
        $invoice->subscription()
            ->where('status', SubscriptionStatus::PENDING->value)
            ->update([
                'status'       => SubscriptionStatus::CANCELLED->value,
                'cancelled_at' => now(),
            ]);

        $this->forget($invoice->user_id);
    }

    /**
     * Kedaluwarsakan langganan yang masa aktifnya sudah lewat.
     *
     * Dijalankan scheduler. Tanpa ini, `expired_at` yang sudah lewat tetap
     * berstatus ACTIVE di basis data — dan setiap tempat yang memeriksa akses
     * harus ingat membandingkan tanggalnya sendiri. Satu yang lupa berarti
     * langganan habis yang masih bisa menonton.
     *
     * @return int jumlah yang dikedaluwarsakan
     */
    public function expireDue(int $limit = 500): int
    {
        $langganan = $this->repository->due($limit);

        foreach ($langganan as $satu) {

            $satu->forceFill(['status' => SubscriptionStatus::EXPIRED->value])->save();

            $this->syncUserFlags($satu->user_id);
        }

        if ($langganan->isNotEmpty()) {
            $this->log('info', 'membership.expired', ['jumlah' => $langganan->count()]);
        }

        return $langganan->count();
    }

    /** Buang cache status satu pengguna. */
    public function forget(int $userId): void
    {
        Cache::forget(self::CACHE.$userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Kapan masa aktif baru mulai dihitung.
     *
     * Dari sisa langganan yang masih berjalan bila ada — lihat alasannya di
     * docblock kelas.
     */
    private function startFrom(int $userId): \Illuminate\Support\Carbon
    {
        $user = User::find($userId);

        $berjalan = $user !== null ? $this->repository->active($user) : null;

        // Sisa yang masih berjalan jadi titik mulai. Tanpa tanggal berakhir
        // (langganan seumur hidup) tidak perlu ditambah apa pun, jadi
        // dihitung dari sekarang -- menambahkan hari ke ketiadaan batas
        // justru MEMBERI batas yang tadinya tidak ada.
        return $berjalan?->expired_at?->copy() ?? now();
    }

    /**
     * Samakan kolom ringkas di `users` dengan keadaan langganannya.
     *
     * `users.is_premium` dan `users.premium_expired_at` dibaca
     * `EpisodeAccessService` — jalur yang sama dipakai pemutar website DAN
     * bot Telegram sejak Sprint 8.5. Membiarkannya tidak sinkron berarti
     * pembayaran yang berhasil tidak membuka apa pun.
     */
    private function syncUserFlags(int $userId): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $aktif = $this->repository->active($user);

        DB::table('users')->where('id', $userId)->update([
            'is_premium'         => $aktif !== null,
            'premium_expired_at' => $aktif?->expired_at,
        ]);

        $this->forget($userId);
    }

}
