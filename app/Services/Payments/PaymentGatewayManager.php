<?php

namespace App\Services\Payments;

use App\Models\PaymentProvider;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Support\Collection;

/**
 * Menyerahkan provider ke gateway yang menjalankannya.
 *
 * Ini satu-satunya tempat di seluruh proyek yang tahu kelas mana milik driver
 * mana. Business Logic Membership memanggil manager ini dengan sebuah
 * `PaymentProvider`, dan menerima sesuatu yang memenuhi
 * `PaymentGatewayInterface` — tanpa pernah menyebut nama kelas gateway.
 *
 * Pola yang sama dengan `StorageManager` (7.1).
 */
class PaymentGatewayManager
{
    /** @var array<string,PaymentGatewayInterface> */
    private array $memo = [];

    /**
     * Gateway untuk satu provider.
     *
     * Instansinya dipakai ulang per driver: gateway tidak menyimpan keadaan
     * milik provider mana pun — seluruh keadaan datang lewat argumen — jadi
     * membangunnya berulang tidak ada gunanya.
     */
    public function for(PaymentProvider $provider): PaymentGatewayInterface
    {
        $driver = $provider->driver->value;

        return $this->memo[$driver] ??= app($provider->driver->gateway());
    }

    /**
     * Provider default yang siap dipakai.
     *
     * @throws PaymentException
     */
    public function default(): PaymentProvider
    {
        $provider = PaymentProvider::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($provider !== null && $provider->isUsable()) {
            return $provider;
        }

        // Default yang tidak bisa dipakai lebih buruk daripada tidak ada
        // default: pengguna sampai di checkout lalu ditolak. Jatuh ke provider
        // aktif pertama yang benar-benar siap.
        $cadangan = $this->usable()->first();

        if ($cadangan === null) {
            throw PaymentException::noProvider();
        }

        return $cadangan;
    }

    /**
     * Provider yang benar-benar bisa menerima pembayaran.
     *
     * Yang disaring bukan sekadar `is_active`: driver yang masih kerangka dan
     * kredensial yang belum lengkap sama-sama gagal di tengah checkout, dan
     * itu tempat paling buruk untuk gagal.
     *
     * @return Collection<int,PaymentProvider>
     */
    public function usable(): Collection
    {
        return PaymentProvider::query()
            ->active()
            ->get()
            ->filter(fn (PaymentProvider $p) => $p->isUsable())
            ->values();
    }

    /**
     * Cari provider dari slug atau id.
     *
     * @throws PaymentException
     */
    public function find(int|string $identifier): PaymentProvider
    {
        $provider = PaymentProvider::query()
            ->when(
                is_numeric($identifier),
                fn ($q) => $q->where('id', (int) $identifier),
                fn ($q) => $q->where('slug', $identifier)
            )
            ->first();

        if ($provider === null) {
            throw PaymentException::providerUnusable(
                (string) $identifier,
                'provider tidak ditemukan.'
            );
        }

        return $provider;
    }

    /**
     * Pastikan provider siap, atau tolak dengan alasannya.
     *
     * @throws PaymentException
     */
    public function assertUsable(PaymentProvider $provider): void
    {
        if ($alasan = $provider->blocker()) {
            throw PaymentException::providerUnusable($provider->name, $alasan);
        }
    }
}
