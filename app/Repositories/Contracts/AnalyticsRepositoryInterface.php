<?php

namespace App\Repositories\Contracts;

use App\Enums\AnalyticsPeriod;
use Illuminate\Support\Collection;

/**
 * Seluruh pertanyaan agregat untuk Business Intelligence.
 *
 * ## Kenapa satu kontrak, bukan lima
 *
 * Business, konten, Telegram, storage, dan keuangan terlihat seperti lima
 * urusan berbeda, tetapi pertanyaannya berbagi bentuk yang sama persis:
 * "berapa jumlahnya", "berapa per periode", "mana yang teratas". Memecahnya
 * jadi lima kontrak berarti tiga bentuk itu ditulis lima kali.
 *
 * ## Satu sumber kebenaran untuk pendapatan
 *
 * Pendapatan dihitung dari `invoices` yang berstatus lunas — bukan dari
 * `subscriptions.price`. Sejak Phase 10 keduanya bisa berbeda: langganan yang
 * diberikan admin punya harga tetapi tidak ada uang yang masuk, dan biaya
 * layanan provider hanya tercatat di invoice.
 *
 * Sebelum Phase 11, laporan pendapatan dan kartu dashboard sama-sama
 * menjumlahkan `subscriptions.price`. Keduanya salah dengan cara yang sama,
 * jadi angkanya cocok satu sama lain — dan itu justru yang membuatnya tidak
 * pernah dicurigai.
 */
interface AnalyticsRepositoryInterface
{
    /*
    |--------------------------------------------------------------------------
    | Bisnis
    |--------------------------------------------------------------------------
    */

    /** @return array<string,int|float> total pengguna, aktif, premium, gratis */
    public function userTotals(): array;

    /** Pendaftaran baru per periode. @return Collection<string,int> */
    public function registrationsPerPeriod(AnalyticsPeriod $period): Collection;

    /**
     * Pertumbuhan dibanding periode sebelumnya, dalam persen.
     *
     * @return array{sekarang:int|float, sebelumnya:int|float, persen:float}
     */
    public function growth(string $metric, AnalyticsPeriod $period): array;

    /*
    |--------------------------------------------------------------------------
    | Konten
    |--------------------------------------------------------------------------
    */

    /** @return array<string,int> total drama, episode, unggahan */
    public function contentTotals(): array;

    /** @return Collection drama teratas berdasarkan tontonan */
    public function topDramas(int $limit = 10): Collection;

    /** @return Collection episode teratas berdasarkan riwayat tonton */
    public function topEpisodes(int $limit = 10): Collection;

    /** @return Collection drama dengan favorit terbanyak */
    public function topFavorited(int $limit = 10): Collection;

    /**
     * Statistik penyelesaian tontonan.
     *
     * @return array{total:int, selesai:int, lanjut:int, rate:float}
     */
    public function completion(): array;

    /** Unggahan berkas per periode. @return Collection<string,int> */
    public function uploadsPerPeriod(AnalyticsPeriod $period): Collection;

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    /** @return array<string,int> pengguna, sinkron, gagal, tersedia untuk deep link */
    public function telegramTotals(): array;

    /** @return array<string,int> jumlah video per status sinkronisasi */
    public function telegramSyncBreakdown(): array;

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    /** @return array<string,int|float> jumlah berkas dan total ukuran */
    public function storageTotals(): array;

    /** @return Collection pemakaian per provider */
    public function storagePerProvider(): Collection;

    /** Pertumbuhan ukuran tersimpan per periode. @return Collection<string,float> */
    public function storageGrowth(AnalyticsPeriod $period): Collection;

    /*
    |--------------------------------------------------------------------------
    | Keuangan
    |--------------------------------------------------------------------------
    */

    /** @return array<string,int|float> pendapatan total, bulan ini, hari ini */
    public function revenueTotals(): array;

    /** Pendapatan per periode, dari invoice lunas. @return Collection<string,float> */
    public function revenuePerPeriod(AnalyticsPeriod $period): Collection;

    /** @return array<string,int> jumlah invoice per status */
    public function invoiceBreakdown(): array;

    /**
     * Tingkat keberhasilan pembayaran.
     *
     * @return array{total:int, sukses:int, gagal:int, rate:float}
     */
    public function paymentSuccessRate(AnalyticsPeriod $period): array;

    /** @return array<string,int> langganan aktif, kedaluwarsa, dibatalkan, menunggu */
    public function subscriptionBreakdown(): array;

    /**
     * Perpanjangan: pengguna yang membeli lebih dari satu kali.
     *
     * @return array{pelanggan:int, berulang:int, rate:float}
     */
    public function renewalStats(): array;

    /** @return Collection paket terlaris beserta pendapatannya */
    public function revenuePerPlan(int $limit = 10): Collection;
}
