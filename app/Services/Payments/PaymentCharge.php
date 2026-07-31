<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;

/**
 * Jawaban provider atas permintaan pembayaran baru.
 *
 * Bentuk yang sama untuk semua driver. Inilah yang membuat Business Logic
 * Membership tidak perlu tahu provider mana yang dipakai: ia menerima objek
 * ini, bukan array mentah yang bentuknya berbeda di setiap gateway.
 */
final class PaymentCharge
{
    public function __construct(
        /** Id transaksi di sisi provider. Null untuk provider manual. */
        public readonly ?string $externalId,

        /** URL tempat pengguna menyelesaikan pembayaran. Null untuk manual. */
        public readonly ?string $checkoutUrl,

        /** Keadaan awal. Hampir selalu PENDING. */
        public readonly PaymentStatus $status,

        /** Jawaban mentah provider, untuk penelusuran sengketa. */
        public readonly array $raw = [],

        /** Batas waktu pembayaran menurut provider. */
        public readonly ?CarbonInterface $expiresAt = null,

        /** Metode yang sudah ditentukan di muka, bila ada. */
        public readonly ?string $method = null,
    ) {
    }
}
