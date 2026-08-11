<?php

namespace App\Repositories\Contracts;

use App\Enums\PaymentRegion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Akses data membership.
 *
 * ## Kenapa bentuknya berubah di Phase 10
 *
 * Sebelumnya kontrak ini punya `subscribe()` yang langsung membuat langganan
 * aktif selama N hari — tanpa invoice, tanpa pembayaran, tanpa memeriksa
 * apakah ada langganan yang masih berjalan. Itu bukan akses data, itu aturan
 * bisnis, dan aturan bisnisnya pun salah: memanggilnya dua kali menimpa masa
 * aktif yang belum habis.
 *
 * Aturan bisnisnya sekarang ada di `MembershipService`. Yang tinggal di sini
 * adalah pertanyaan ke basis data.
 */
interface MembershipRepositoryInterface
{
    /** Paket yang sedang ditawarkan, urut tampilan. */
    /**
     * Paket aktif, boleh disaring per wilayah pembayaran.
     *
     * Argumennya opsional supaya pemanggil lama — mis. halaman membership di
     * website — tetap mendapat seluruh paket tanpa diubah.
     */
    public function plans(?PaymentRegion $region = null): Collection;

    /**
     * Langganan yang sedang memberi akses, atau null.
     *
     * Tanggal berakhir ikut diperiksa di sini, bukan dipercayakan pada status
     * saja: scheduler yang mengedaluwarsakan langganan berjalan berkala dan
     * bisa terlambat beberapa menit.
     */
    public function active(User $user): ?Subscription;

    /**
     * Langganan terakhir yang sudah berakhir atau dibatalkan.
     *
     * Membedakan "pernah berlangganan" dari "belum pernah sama sekali".
     */
    public function lastEnded(User $user): ?Subscription;

    /** Riwayat langganan, terbaru lebih dulu. */
    public function history(User $user, int $limit = 50): Collection;

    /**
     * Langganan aktif yang masa berlakunya sudah lewat.
     *
     * @return Collection<int,Subscription>
     */
    public function due(int $limit = 500): Collection;
}
