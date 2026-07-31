<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;

/**
 * Keadaan sebuah pembayaran menurut provider.
 *
 * Dipakai dua arah: hasil membaca callback, dan hasil bertanya langsung lewat
 * `verify()`. Keduanya menjawab pertanyaan yang sama — "sudah dibayar atau
 * belum" — jadi keduanya mengembalikan bentuk yang sama.
 *
 * Menyatukannya bukan penghematan: kalau bentuknya berbeda, `PaymentCallbackService`
 * akan punya dua jalur pemrosesan untuk fakta yang identik, dan salah satunya
 * pasti akan tertinggal saat aturannya berubah.
 */
final class PaymentResult
{
    public function __construct(
        public readonly PaymentStatus $status,

        /** Referensi milik kita, dibaca dari payload provider. */
        public readonly ?string $reference = null,

        public readonly ?string $externalId = null,

        /** Nominal yang benar-benar dibayar, untuk dicocokkan. */
        public readonly ?float $amount = null,

        public readonly ?string $method = null,

        public readonly array $raw = [],

        /** Keterangan bila gagal. */
        public readonly ?string $message = null,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }
}
