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
 * QRIS statis, diverifikasi admin.
 *
 * ## Kenapa terpisah dari ManualTransferGateway
 *
 * Keduanya sama-sama diverifikasi manusia, dan menggabungkannya sempat
 * terlihat masuk akal. Yang membedakan bukan cara verifikasinya, melainkan
 * apa yang harus dipegang sistem sebelum pembayaran bisa dimulai sama sekali:
 * transfer bank butuh tiga potong TEKS, QRIS butuh satu BERKAS GAMBAR.
 *
 * Berkas yang belum diunggah tidak bisa dijaga oleh `requiredFields()` —
 * kolomnya bukan bagian dari `credentials`. Jadi penjagaannya harus di tempat
 * lain, dan driver yang menyatukan keduanya akan punya satu jalur yang
 * penjagaannya berlaku dan satu jalur yang tidak. Itu persis jenis
 * ketidakseragaman yang baru ketahuan saat ada pengguna yang menekan tombol
 * bayar dan tidak menerima gambar apa pun.
 *
 * ## Tidak ada API, tidak ada callback
 *
 * QRIS statis tidak memberi tahu siapa pun bahwa ia dipindai. Satu-satunya
 * yang tahu uangnya masuk adalah pemilik rekening — yaitu admin, yang melihat
 * mutasi lalu menekan ACC. Jalurnya tetap `PaymentCallbackService::apply()`
 * yang sama dengan callback gateway sungguhan, sehingga idempotensi,
 * penjagaan perpindahan status, dan aktivasi membership berlaku persis sama.
 */
class QrisGateway extends AbstractGateway
{
    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge {

        foreach (array_keys($provider->driver->requiredFields()) as $field) {
            $this->credential($provider, $field);
        }

        // Gambar diperiksa di sini juga, bukan hanya saat provider
        // diaktifkan. Berkasnya bisa dihapus dari file manager setelah
        // provider aktif, dan checkout yang berhasil tanpa gambar berarti
        // pengguna menerima tagihan yang tidak bisa dibayarnya.
        if (blank($provider->qris_image_path)) {
            throw PaymentException::providerUnusable(
                $provider->name,
                'gambar QRIS belum diunggah.'
            );
        }

        $this->log('info', 'charge.qris', [
            'invoice'   => $invoice->number,
            'reference' => $transaction->reference,
        ]);

        return new PaymentCharge(
            externalId: null,
            checkoutUrl: null,
            status: PaymentStatus::PENDING,
            raw: [
                'merchant' => $provider->credential('merchant_name'),
                'qris'     => $provider->qris_image_path,
            ],
            expiresAt: $invoice->due_at,
            method: 'qris',
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
            message: 'Pembayaran QRIS menunggu verifikasi admin.'
        );
    }

    /**
     * Tidak ada callback untuk QRIS statis.
     *
     * Permintaan callback yang menyebut provider QRIS bukan pembayaran yang
     * sah — itu seseorang yang mencoba mengaktifkan membership tanpa membayar.
     *
     * @throws PaymentException
     */
    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = []
    ): PaymentResult {

        $this->log('warning', 'callback.rejected', [
            'provider' => $provider->slug,
            'sebab'    => 'driver qris statis tidak menerima callback',
        ]);

        throw PaymentException::invalidSignature($provider->slug);
    }
}
