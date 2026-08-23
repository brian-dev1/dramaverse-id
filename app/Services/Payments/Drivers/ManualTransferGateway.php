<?php

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentCharge;
use App\Services\Payments\PaymentResult;

/**
 * Transfer bank manual, diverifikasi admin.
 *
 * ## Kenapa driver ini ada, dan kenapa ia yang pertama selesai
 *
 * Ia tidak memanggil API mana pun, jadi ia bisa diuji hari ini juga — tanpa
 * akun gateway, tanpa kredensial, tanpa sandbox. Itu membuat seluruh alur
 * lain bisa dibuktikan bekerja: invoice terbentuk, transaksi tercatat,
 * membership aktif otomatis setelah verifikasi, langganan berakhir pada
 * waktunya.
 *
 * Tanpa satu pun driver yang benar-benar jalan, semua yang dibangun Phase 10
 * hanya bisa dibaca, tidak bisa dijalankan — dan sistem pembayaran yang belum
 * pernah dijalankan adalah sistem pembayaran yang belum diketahui benar.
 *
 * ## Verifikasinya manusia, bukan callback
 *
 * Tidak ada callback dan tidak ada tanda tangan. Yang mengubah statusnya
 * adalah admin yang menekan "Verifikasi Manual" setelah melihat mutasi
 * rekening — dan itu tetap melewati `PaymentCallbackService` yang sama,
 * sehingga aturan perpindahan status, penjagaan ganda, dan aktivasi membership
 * berlaku persis sama dengan pembayaran otomatis.
 */
class ManualTransferGateway extends AbstractGateway
{
    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge {

        // Kredensial dibaca hanya untuk memastikan lengkap. Ketiganya
        // ditampilkan ke pengguna sebagai tujuan transfer, jadi kekurangan
        // salah satunya berarti pengguna diberi instruksi yang tidak bisa
        // dijalankan.
        foreach (array_keys($provider->driver->requiredFields()) as $field) {
            $this->credential($provider, $field);
        }

        $this->log('info', 'charge.manual', [
            'invoice'   => $invoice->number,
            'reference' => $transaction->reference,
        ]);

        return new PaymentCharge(
            externalId: null,
            checkoutUrl: null,
            status: PaymentStatus::PENDING,
            raw: [
                'bank'    => $provider->credential('bank_name'),
                'rekening' => $provider->credential('account_number'),
                'atas_nama' => $provider->credential('account_name'),
            ],
            expiresAt: $invoice->due_at,
            method: 'bank_transfer',
        );
    }

    /**
     * Tidak ada yang bisa ditanyakan ke mana pun.
     *
     * Keadaan yang tersimpan di sisi kita ADALAH satu-satunya kebenaran untuk
     * driver ini. Mengembalikan status sekarang apa adanya lebih jujur
     * daripada berpura-pura menanyakannya.
     */
    public function verify(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        return new PaymentResult(
            status: $transaction->status,
            reference: $transaction->reference,
            amount: (float) $transaction->amount,
            method: $transaction->method,
            message: 'Transfer manual menunggu verifikasi admin.'
        );
    }

    /**
     * Tidak ada callback untuk transfer manual.
     *
     * Endpoint callback tetap menolaknya di sini, bukan diam-diam menerima.
     * Kalau ada permintaan callback yang menyebut provider manual, itu bukan
     * pembayaran yang sah — itu seseorang yang mencoba mengaktifkan membership
     * tanpa membayar.
     *
     * @throws PaymentException
     */
    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = [],
        ?string $rawBody = null
    ): PaymentResult {

        $this->log('warning', 'callback.rejected', [
            'provider' => $provider->slug,
            'sebab'    => 'driver manual tidak menerima callback',
        ]);

        throw PaymentException::invalidSignature($provider->slug);
    }
}
