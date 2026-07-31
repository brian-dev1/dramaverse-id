<?php

namespace App\Repositories;

use App\Enums\AnalyticsPeriod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TelegramSyncStatus;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchHistory;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lihat AnalyticsRepositoryInterface untuk aturan dan alasannya.
 *
 * Seluruh pengelompokan waktu lewat `bucket()` — satu tempat yang menyusun
 * `DATE_FORMAT`, menjalankan query, lalu mengisi periode kosong dengan nol.
 * Menulisnya di setiap method berarti sepuluh salinan dari satu pola, dan
 * yang satu pasti lupa mengisi periode kosongnya.
 */
class AnalyticsRepository implements AnalyticsRepositoryInterface
{
    /*
    |--------------------------------------------------------------------------
    | Bisnis
    |--------------------------------------------------------------------------
    */

    public function userTotals(): array
    {
        $pengguna = User::query()->where('is_admin', false);

        $premium = (clone $pengguna)
            ->where('is_premium', true)
            ->where(fn ($q) => $q->whereNull('premium_expired_at')
                ->orWhere('premium_expired_at', '>', now()))
            ->count();

        $total = (clone $pengguna)->count();

        return [
            'total'    => $total,
            'aktif'    => (clone $pengguna)->where('last_seen_at', '>=', now()->subDays(30))->count(),
            'premium'  => $premium,
            // Gratis dihitung sebagai selisih, bukan query terpisah dengan
            // `is_premium = false`. Keduanya seharusnya sama; kalau berbeda,
            // yang benar adalah yang menjumlah utuh — pengguna tidak boleh
            // hilang dari kedua kelompok karena kolom penandanya basi.
            'gratis'   => max(0, $total - $premium),
            'telegram' => (clone $pengguna)->whereNotNull('telegram_id')->count(),
            'baru'     => (clone $pengguna)->whereDate('created_at', today())->count(),
        ];
    }

    public function registrationsPerPeriod(AnalyticsPeriod $period): Collection
    {
        return $this->bucket(
            User::query()->where('is_admin', false),
            'created_at',
            $period
        );
    }

    public function growth(string $metric, AnalyticsPeriod $period): array
    {
        [$mulaiSekarang, $mulaiSebelum] = [$period->since(), $this->previousStart($period)];

        $hitung = function ($dari, $sampai) use ($metric) {

            return match ($metric) {

                'revenue' => (float) Invoice::query()
                    ->where('status', PaymentStatus::PAID->value)
                    ->whereBetween('paid_at', [$dari, $sampai])
                    ->sum('total'),

                'subscription' => Subscription::query()
                    ->whereBetween('created_at', [$dari, $sampai])
                    ->count(),

                default => User::query()
                    ->where('is_admin', false)
                    ->whereBetween('created_at', [$dari, $sampai])
                    ->count(),
            };
        };

        $sekarang = $hitung($mulaiSekarang, now());

        $sebelumnya = $hitung($mulaiSebelum, $mulaiSekarang);

        // Pertumbuhan dari nol tidak bisa dinyatakan dalam persen. Yang
        // dikembalikan 100 bila ada isinya, 0 bila sama-sama kosong —
        // pembagian dengan nol menghasilkan infinity yang merusak grafik.
        $persen = $sebelumnya > 0
            ? round((($sekarang - $sebelumnya) / $sebelumnya) * 100, 1)
            : ($sekarang > 0 ? 100.0 : 0.0);

        return [
            'sekarang'   => $sekarang,
            'sebelumnya' => $sebelumnya,
            'persen'     => $persen,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Konten
    |--------------------------------------------------------------------------
    */

    public function contentTotals(): array
    {
        return [
            'drama'     => Drama::count(),
            'episode'   => Episode::count(),
            'video'     => EpisodeVideo::count(),
            'tontonan'  => (int) Drama::sum('views'),
            'hari_ini'  => WatchHistory::whereDate('last_watched_at', today())->count(),
        ];
    }

    public function topDramas(int $limit = 10): Collection
    {
        return Drama::query()
            ->withCount('watchHistories')
            ->orderByDesc('views')
            ->limit($limit)
            ->get(['id', 'title', 'views']);
    }

    public function topEpisodes(int $limit = 10): Collection
    {
        return Episode::query()
            ->with('drama:id,title')
            ->withCount('watchHistories')
            ->orderByDesc('watch_histories_count')
            ->limit($limit)
            ->get(['id', 'drama_id', 'episode_number', 'title']);
    }

    public function topFavorited(int $limit = 10): Collection
    {
        return Drama::query()
            ->withCount('favorites')
            ->orderByDesc('favorites_count')
            ->limit($limit)
            ->get(['id', 'title']);
    }

    public function completion(): array
    {
        $total = WatchHistory::count();

        $selesai = WatchHistory::where('completed', true)->count();

        return [
            'total'   => $total,
            'selesai' => $selesai,
            // "Lanjut menonton" adalah yang sudah dimulai tapi belum selesai.
            'lanjut'  => max(0, $total - $selesai),
            'rate'    => $total > 0 ? round($selesai / $total * 100, 1) : 0.0,
        ];
    }

    public function uploadsPerPeriod(AnalyticsPeriod $period): Collection
    {
        return $this->bucket(EpisodeVideo::query(), 'created_at', $period);
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    public function telegramTotals(): array
    {
        return [
            'pengguna'  => User::whereNotNull('telegram_id')->count(),
            'aktif'     => User::whereNotNull('telegram_id')->where('is_active', true)->count(),
            'diblokir'  => User::whereNotNull('telegram_id')->where('is_banned', true)->count(),
            // Episode yang benar-benar bisa dikirim lewat bot. Ini angka yang
            // menentukan seberapa berguna deep link: tautan ke episode yang
            // belum tersinkron akan dijawab "belum siap".
            'siap_kirim' => EpisodeVideo::whereNotNull('telegram_file_id')->count(),
        ];
    }

    public function telegramSyncBreakdown(): array
    {
        $hasil = [];

        foreach (TelegramSyncStatus::cases() as $status) {
            $hasil[$status->value] = EpisodeVideo::where('sync_status', $status->value)->count();
        }

        return $hasil;
    }

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    public function storageTotals(): array
    {
        return [
            'berkas' => EpisodeVideo::count(),
            'ukuran' => (int) EpisodeVideo::sum('size'),
        ];
    }

    public function storagePerProvider(): Collection
    {
        return EpisodeVideo::query()
            ->select('storage_provider_id')
            ->selectRaw('COUNT(*) as berkas')
            ->selectRaw('COALESCE(SUM(size), 0) as ukuran')
            ->with('provider:id,name,slug')
            ->groupBy('storage_provider_id')
            ->orderByDesc('ukuran')
            ->get();
    }

    public function storageGrowth(AnalyticsPeriod $period): Collection
    {
        return $this->bucket(
            EpisodeVideo::query(),
            'created_at',
            $period,
            'COALESCE(SUM(size), 0)'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Keuangan
    |--------------------------------------------------------------------------
    */

    public function revenueTotals(): array
    {
        $lunas = fn (): Builder => Invoice::query()->where('status', PaymentStatus::PAID->value);

        return [
            'total'     => (float) $lunas()->sum('total'),
            'bulan_ini' => (float) $lunas()->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
            'hari_ini'  => (float) $lunas()->whereDate('paid_at', today())->sum('total'),
            'invoice'   => $lunas()->count(),
        ];
    }

    public function revenuePerPeriod(AnalyticsPeriod $period): Collection
    {
        return $this->bucket(
            Invoice::query()->where('status', PaymentStatus::PAID->value),
            'paid_at',
            $period,
            'COALESCE(SUM(total), 0)'
        );
    }

    public function invoiceBreakdown(): array
    {
        $hasil = [];

        foreach (PaymentStatus::cases() as $status) {
            $hasil[$status->value] = Invoice::where('status', $status->value)->count();
        }

        return $hasil;
    }

    public function paymentSuccessRate(AnalyticsPeriod $period): array
    {
        $sejak = $period->since();

        $total = PaymentTransaction::where('created_at', '>=', $sejak)->count();

        $sukses = PaymentTransaction::where('created_at', '>=', $sejak)
            ->whereIn('status', [PaymentStatus::PAID->value, PaymentStatus::REFUNDED->value])
            ->count();

        // Yang masih PENDING tidak dihitung gagal: ia belum selesai, dan
        // memasukkannya ke pembilang kegagalan membuat angka keberhasilan
        // selalu terlihat buruk di awal periode.
        $gagal = PaymentTransaction::where('created_at', '>=', $sejak)
            ->whereIn('status', [
                PaymentStatus::FAILED->value,
                PaymentStatus::EXPIRED->value,
                PaymentStatus::CANCELLED->value,
            ])
            ->count();

        $selesai = $sukses + $gagal;

        return [
            'total'  => $total,
            'sukses' => $sukses,
            'gagal'  => $gagal,
            'rate'   => $selesai > 0 ? round($sukses / $selesai * 100, 1) : 0.0,
        ];
    }

    public function subscriptionBreakdown(): array
    {
        $hasil = [];

        foreach (SubscriptionStatus::cases() as $status) {
            $hasil[$status->value] = Subscription::where('status', $status->value)->count();
        }

        return $hasil;
    }

    public function renewalStats(): array
    {
        // Pelanggan yang punya lebih dari satu invoice lunas. Dihitung dari
        // invoice, bukan dari subscriptions: langganan yang diberikan admin
        // bukan perpanjangan yang dibayar.
        $perPelanggan = Invoice::query()
            ->where('status', PaymentStatus::PAID->value)
            ->select('user_id')
            ->selectRaw('COUNT(*) as jumlah')
            ->groupBy('user_id')
            ->get();

        $pelanggan = $perPelanggan->count();

        $berulang = $perPelanggan->where('jumlah', '>', 1)->count();

        return [
            'pelanggan' => $pelanggan,
            'berulang'  => $berulang,
            'rate'      => $pelanggan > 0 ? round($berulang / $pelanggan * 100, 1) : 0.0,
        ];
    }

    public function revenuePerPlan(int $limit = 10): Collection
    {
        return Invoice::query()
            ->where('status', PaymentStatus::PAID->value)
            ->select('plan_name')
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw('COALESCE(SUM(total), 0) as pendapatan')
            ->groupBy('plan_name')
            ->orderByDesc('pendapatan')
            ->limit($limit)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Pengelompokan waktu
    |--------------------------------------------------------------------------
    */

    /**
     * Kelompokkan sebuah query per periode.
     *
     * Periode tanpa data tetap muncul dengan nilai nol. Ini bukan kosmetik:
     * grafik yang hanya menampilkan hari yang kebetulan ada datanya akan
     * melompati hari kosong, dan garis yang melompat menyembunyikan justru
     * yang paling ingin dilihat — kapan berhentinya.
     *
     * @param  string  $agregat  ekspresi SQL, bawaan menghitung baris
     * @return Collection<string,int|float>
     */
    private function bucket(
        Builder $query,
        string $kolom,
        AnalyticsPeriod $period,
        string $agregat = 'COUNT(*)'
    ): Collection {

        $rows = $query
            ->whereNotNull($kolom)
            ->where($kolom, '>=', $period->since())
            ->selectRaw("DATE_FORMAT({$kolom}, ?) as kunci", [$period->sqlFormat()])
            ->selectRaw("{$agregat} as nilai")
            ->groupBy('kunci')
            ->pluck('nilai', 'kunci');

        $hasil = collect();

        foreach ($period->buckets() as $kunci) {
            $hasil->put($kunci, (float) ($rows[$kunci] ?? 0));
        }

        return $hasil;
    }

    /** Awal periode sebelumnya, untuk perbandingan pertumbuhan. */
    private function previousStart(AnalyticsPeriod $period): \Illuminate\Support\Carbon
    {
        $mulai = $period->since();

        $panjang = $mulai->diffInSeconds(now());

        return $mulai->copy()->subSeconds((int) $panjang);
    }
}
