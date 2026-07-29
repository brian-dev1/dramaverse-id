<?php

namespace App\Http\Controllers\Admin;

use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends AdminCrudController
{
    /** Status yang dikenali sistem. */
    private const STATUSES = [
        'pending'   => 'Menunggu pembayaran',
        'active'    => 'Aktif',
        'expired'   => 'Kedaluwarsa',
        'cancelled' => 'Dibatalkan',
    ];

    protected function model(): string
    {
        return Subscription::class;
    }

    protected function routeKey(): string
    {
        return 'subscription';
    }

    protected function label(): string
    {
        return 'Langganan';
    }

    protected function columns(): array
    {
        return [
            'Pengguna' => 'user.name',
            'Paket'    => 'plan.name',
            'Harga'    => 'price',
            'Status'   => 'status',
            'Mulai'    => 'started_at',
            'Berakhir' => 'expired_at',
            'Referensi'=> 'payment_reference',
        ];
    }

    protected function sortable(): array
    {
        return ['price', 'status', 'started_at', 'expired_at'];
    }

    protected function searchable(): array
    {
        return ['payment_reference'];
    }

    protected function relations(): array
    {
        return ['user:id,name,telegram_username', 'plan:id,name'];
    }

    protected function filters(): array
    {
        return [
            'status' => ['label' => 'Status', 'options' => self::STATUSES],
            'membership_plan_id' => [
                'label'   => 'Paket',
                'options' => MembershipPlan::orderBy('sort_order')->pluck('name', 'id')->all(),
            ],
        ];
    }

    protected function formData(?Model $model = null): array
    {
        return [
            'plans'    => MembershipPlan::active()->get(['id', 'name', 'price', 'duration']),
            'users'    => User::where('is_admin', false)->orderBy('name')->get(['id', 'name', 'telegram_username']),
            'statuses' => self::STATUSES,
        ];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [
            'user_id'            => ['required', 'integer', 'exists:users,id'],
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'price'              => ['required', 'numeric', 'min:0'],
            'status'             => ['required', Rule::in(array_keys(self::STATUSES))],
            'started_at'         => ['nullable', 'date'],
            'expired_at'         => ['nullable', 'date', 'after:started_at'],
            'payment_reference'  => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model = null): array
    {
        // Langganan aktif tanpa tanggal dianggap mulai sekarang, dan masa
        // berlakunya dihitung dari durasi paket.
        if ($data['status'] === 'active') {
            $data['started_at'] ??= now();

            if (blank($data['expired_at'] ?? null)) {
                $plan = MembershipPlan::find($data['membership_plan_id']);
                $data['expired_at'] = $plan
                    ? \Illuminate\Support\Carbon::parse($data['started_at'])->addDays($plan->duration)
                    : null;
            }
        }

        return $data;
    }

    protected function bulkActions(): array
    {
        return [
            'activate' => 'Aktifkan',
            'cancel'   => 'Batalkan',
            'expire'   => 'Tandai kedaluwarsa',
        ];
    }

    protected function applyBulk(string $action, Builder $query): int
    {
        return match ($action) {
            'activate' => $query->update(['status' => 'active', 'started_at' => now()]),
            'cancel'   => $query->update(['status' => 'cancelled']),
            'expire'   => $query->update(['status' => 'expired']),
            default    => 0,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Aksi khusus
    |--------------------------------------------------------------------------
    */

    /** Memperpanjang langganan sepanjang durasi paketnya. */
    public function renew(int $id): RedirectResponse
    {
        $subscription = $this->findOrFail($id);
        $plan = $subscription->plan;

        if (! $plan) {
            return back()->withErrors(['plan' => 'Paket langganan ini sudah tidak ada.']);
        }

        // Perpanjangan dihitung dari tanggal berakhir bila masih berlaku,
        // atau dari hari ini bila sudah lewat.
        $base = $subscription->expired_at && $subscription->expired_at->isFuture()
            ? $subscription->expired_at
            : now();

        $subscription->update([
            'status'     => 'active',
            'started_at' => $subscription->started_at ?? now(),
            'expired_at' => $base->copy()->addDays($plan->duration),
        ]);

        app(ActivityLogger::class)->log('diperpanjang', 'subscription', $subscription, [
            'durasi' => $plan->duration,
        ]);

        return back()->with('status', 'Langganan diperpanjang '.$plan->duration.' hari.');
    }

    /** Membatalkan langganan tanpa menghapus riwayatnya. */
    public function cancel(int $id): RedirectResponse
    {
        $subscription = $this->findOrFail($id);

        $subscription->update(['status' => 'cancelled']);

        app(ActivityLogger::class)->log('dibatalkan', 'subscription', $subscription);

        return back()->with('status', 'Langganan dibatalkan.');
    }
}
