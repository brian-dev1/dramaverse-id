<?php

namespace App\Services\Analytics;

use App\Enums\AnalyticsPeriod;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Menyusun angka dashboard, dan menjaga halamannya tetap cepat.
 *
 * ## Kenapa cache di sini, bukan di repository
 *
 * Repository menjawab pertanyaan tunggal; halaman membutuhkan belasan
 * jawaban sekaligus. Yang mahal bukan satu query, melainkan dibukanya
 * halaman itu berulang kali oleh beberapa admin dalam menit yang sama.
 *
 * Karena itu yang di-cache adalah SEKSI utuh, bukan tiap angka. Satu kunci
 * cache per seksi per periode berarti satu kali baca cache untuk seluruh
 * kartu dan grafik di satu tab.
 *
 * TTL-nya pendek dengan sengaja. Angka analitik yang telat lima menit tidak
 * merugikan siapa pun; angka yang telat satu jam membuat orang berhenti
 * memercayai dashboard-nya, dan dashboard yang tidak dipercaya sama saja
 * dengan tidak ada.
 */
class AnalyticsService
{
    public const SECTIONS = ['business', 'content', 'telegram', 'storage', 'financial'];

    private const PREFIX = 'analytics:';

    public function __construct(
        protected AnalyticsRepositoryInterface $repository
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Seksi
    |--------------------------------------------------------------------------
    */

    public function business(AnalyticsPeriod $period): array
    {
        return $this->remember('business', $period, fn () => [
            'users'         => $this->repository->userTotals(),
            'registrations' => $this->chart($this->repository->registrationsPerPeriod($period), $period),
            'growth'        => [
                'user'         => $this->repository->growth('user', $period),
                'revenue'      => $this->repository->growth('revenue', $period),
                'subscription' => $this->repository->growth('subscription', $period),
            ],
            'revenue'       => $this->repository->revenueTotals(),
            'subscriptions' => $this->repository->subscriptionBreakdown(),
        ]);
    }

    public function content(AnalyticsPeriod $period): array
    {
        return $this->remember('content', $period, fn () => [
            'totals'     => $this->repository->contentTotals(),
            'completion' => $this->repository->completion(),
            'uploads'    => $this->chart($this->repository->uploadsPerPeriod($period), $period),
            'topDramas'  => $this->repository->topDramas(),
            'topEpisodes'=> $this->repository->topEpisodes(),
            'topFavorite'=> $this->repository->topFavorited(),
        ]);
    }

    public function telegram(AnalyticsPeriod $period): array
    {
        return $this->remember('telegram', $period, fn () => [
            'totals' => $this->repository->telegramTotals(),
            'sync'   => $this->repository->telegramSyncBreakdown(),
        ]);
    }

    public function storage(AnalyticsPeriod $period): array
    {
        return $this->remember('storage', $period, fn () => [
            'totals'    => $this->repository->storageTotals(),
            'providers' => $this->repository->storagePerProvider(),
            'growth'    => $this->chart($this->repository->storageGrowth($period), $period),
        ]);
    }

    public function financial(AnalyticsPeriod $period): array
    {
        return $this->remember('financial', $period, fn () => [
            'revenue'      => $this->repository->revenueTotals(),
            'perPeriod'    => $this->chart($this->repository->revenuePerPeriod($period), $period),
            'invoices'     => $this->repository->invoiceBreakdown(),
            'success'      => $this->repository->paymentSuccessRate($period),
            'subscriptions'=> $this->repository->subscriptionBreakdown(),
            'renewal'      => $this->repository->renewalStats(),
            'perPlan'      => $this->repository->revenuePerPlan(),
        ]);
    }

    /** Satu seksi berdasarkan namanya. Dipakai controller dan perintah pemanas. */
    public function section(string $nama, AnalyticsPeriod $period): array
    {
        return match ($nama) {
            'content'   => $this->content($period),
            'telegram'  => $this->telegram($period),
            'storage'   => $this->storage($period),
            'financial' => $this->financial($period),
            default     => $this->business($period),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    /**
     * Panaskan seluruh seksi untuk seluruh periode.
     *
     * Dijalankan scheduler. Yang membuka dashboard pertama kali setelah cache
     * kedaluwarsa akan menunggu selama belasan query agregat berjalan —
     * memanaskannya di latar memindahkan tunggu itu ke tempat yang tidak ada
     * orangnya.
     *
     * @return int jumlah seksi yang dihitung
     */
    public function warm(): int
    {
        $jumlah = 0;

        foreach (self::SECTIONS as $seksi) {
            foreach (AnalyticsPeriod::cases() as $periode) {

                $this->forgetSection($seksi, $periode);

                $this->section($seksi, $periode);

                $jumlah++;
            }
        }

        return $jumlah;
    }

    /** Buang seluruh cache analitik. */
    public function forget(): void
    {
        foreach (self::SECTIONS as $seksi) {
            foreach (AnalyticsPeriod::cases() as $periode) {
                $this->forgetSection($seksi, $periode);
            }
        }
    }

    private function forgetSection(string $seksi, AnalyticsPeriod $period): void
    {
        Cache::forget(self::PREFIX.$seksi.':'.$period->value);
    }

    private function remember(string $seksi, AnalyticsPeriod $period, callable $isi): array
    {
        if (! config('analytics.cache.enabled', true)) {
            return $isi();
        }

        return Cache::remember(
            self::PREFIX.$seksi.':'.$period->value,
            (int) config('analytics.cache.ttl', 300),
            $isi
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bentuk grafik
    |--------------------------------------------------------------------------
    */

    /**
     * Ubah hasil pengelompokan jadi bentuk yang dimengerti `x-admin.chart`.
     *
     * Komponen itu sudah ada sejak Sprint 6 dan menerima `labels` serta
     * `values` terpisah. Menyesuaikan diri dengannya berarti nol komponen
     * grafik baru dan nol CSS baru di seluruh Phase 11.
     *
     * @param  Collection<string,int|float>  $data
     * @return array{labels:array<int,string>, values:array<int,float>}
     */
    private function chart(Collection $data, AnalyticsPeriod $period): array
    {
        return [
            'labels' => $data->keys()->map(fn ($k) => $period->labelFor((string) $k))->all(),
            'values' => $data->values()->map(fn ($v) => (float) $v)->all(),
        ];
    }
}
