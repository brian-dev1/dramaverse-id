<?php

namespace App\Repositories;

use App\Enums\PaymentRegion;
use App\Enums\SubscriptionStatus;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\MembershipRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lihat MembershipRepositoryInterface untuk alasan bentuknya.
 *
 * Tidak ada aturan bisnis di sini: tidak ada yang memutuskan berapa lama
 * langganan berlaku, kapan mulai dihitung, atau apa yang terjadi saat
 * seseorang membeli dua kali.
 */
class MembershipRepository implements MembershipRepositoryInterface
{
    public function plans(?PaymentRegion $region = null): Collection
    {
        return MembershipPlan::query()
            ->active()
            ->when($region !== null, fn ($q) => $q->region($region))
            ->get();
    }

    public function active(User $user): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))
            ->latest('expired_at')
            ->first();
    }

    public function lastEnded(User $user): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', [
                SubscriptionStatus::EXPIRED->value,
                SubscriptionStatus::CANCELLED->value,
            ])
            ->latest('expired_at')
            ->first();
    }

    public function history(User $user, int $limit = 50): Collection
    {
        return Subscription::query()
            ->with(['plan', 'invoice'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function due(int $limit = 500): Collection
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->limit($limit)
            ->get();
    }
}
