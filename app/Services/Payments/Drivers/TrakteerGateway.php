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
 * Trakteer.
 *
 * Trakteer tidak punya API pembuatan transaksi. Yang ada adalah halaman
 * dukungan publik dan webhook yang memberi tahu setiap kali ada yang
 * mengirim. Karena itu:
 *
 * - `charge()` tidak memanggil apa pun, hanya menyusun URL halaman beserta
 *   nomor invoice sebagai pesan. Nomor itulah yang menyambungkan pembayaran
 *   ke tagihannya.
 * - `parseCallback()` mengenali invoice dari pesan pendukung, lalu mencocokkan
 *   nominalnya.
 *
 * ## Pengakuan yang harus dibaca sebelum memakainya
 *
 * Penyambungan lewat pesan bebas **tidak sekuat** referensi yang dijamin
 * gateway. Pendukung yang salah ketik nomor invoice akan menghasilkan
 * pembayaran yang tidak tersambung ke tagihan mana pun. Itu bukan kegagalan
 * yang bisa dihilangkan kode — Trakteer memang tidak menyediakan tempat lain
 * untuk menaruhnya.
 *
 * Yang bisa dilakukan, dan dilakukan: pembayaran yang tidak dikenali tetap
 * dicatat lengkap di log dengan seluruh payload-nya, sehingga admin bisa
 * mencocokkannya manual. Tidak ada uang yang hilang tanpa jejak.
 */
class TrakteerGateway extends AbstractGateway
{
    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge {

        $url = rtrim($this->credential($provider, 'page_url'), '/');

        $this->credential($provider, 'webhook_token');

        // Nomor invoice ikut sebagai pesan bawaan. Ini satu-satunya jalan
        // menyambungkan pembayaran ke tagihannya di Trakteer.
        $checkout = $url.'?'.http_build_query([
            'quantity' => 1,
            'message'  => $invoice->number,
        ]);

        $this->log('info', 'charge.trakteer', [
            'invoice'   => $invoice->number,
            'reference' => $transaction->reference,
        ]);

        return new PaymentCharge(
            externalId: null,
            checkoutUrl: $checkout,
            status: PaymentStatus::PENDING,
            raw: ['page_url' => $url],
            expiresAt: $invoice->due_at,
            method: 'trakteer',
        );
    }

    /**
     * Trakteer tidak punya endpoint status.
     *
     * Yang berlaku adalah keadaan yang sudah tersimpan dari callback. Ini
     * disebut apa adanya, bukan disamarkan jadi pemanggilan yang selalu
     * mengembalikan PENDING.
     */
    public function verify(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        return new PaymentResult(
            status: $transaction->status,
            reference: $transaction->reference,
            amount: (float) $transaction->amount,
            method: $transaction->method,
            message: 'Trakteer tidak menyediakan pemeriksaan status; '
                .'yang berlaku adalah callback terakhir yang diterima.'
        );
    }

    /**
     * @throws PaymentException
     */
    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = []
    ): PaymentResult {

        /*
        |----------------------------------------------------------------------
        | 1. Tanda tangan
        |----------------------------------------------------------------------
        |
        | Trakteer mengirim token apa adanya pada header `x-webhook-token`.
        | Dibandingkan dengan hash_equals, bukan `===` — lihat alasannya di
        | AbstractGateway::signatureMatches().
        |
        */

        $token = $this->credential($provider, 'webhook_token');

        $dikirim = (string) ($headers['x-webhook-token'] ?? '');

        if (! $this->signatureMatches($token, $dikirim)) {

            $this->log('warning', 'callback.invalid_signature', [
                'provider' => $provider->slug,
            ]);

            throw PaymentException::invalidSignature($provider->slug);
        }

        /*
        |----------------------------------------------------------------------
        | 2. Sambungkan ke invoice
        |----------------------------------------------------------------------
        */

        $pesan = (string) ($payload['supporter_message'] ?? $payload['message'] ?? '');

        $nomor = $this->extractInvoiceNumber($pesan);

        if ($nomor === null) {

            // Dicatat LENGKAP supaya bisa dicocokkan manual. Uang yang masuk
            // tanpa keterangan tidak boleh hilang tanpa jejak.
            $this->log('warning', 'callback.unmatched', [
                'provider' => $provider->slug,
                'payload'  => $payload,
            ]);

            throw PaymentException::unknownReference($pesan === '' ? '(pesan kosong)' : $pesan);
        }

        $jumlah = (float) ($payload['amount'] ?? $payload['quantity'] ?? 0);

        return new PaymentResult(
            status: PaymentStatus::PAID,
            reference: $nomor,
            externalId: isset($payload['transaction_id'])
                ? (string) $payload['transaction_id']
                : null,
            amount: $jumlah > 0 ? $jumlah : null,
            method: 'trakteer',
            raw: $payload,
        );
    }

    /**
     * Cari nomor invoice di dalam pesan bebas.
     *
     * Polanya sengaja ketat dan tidak peduli besar-kecil huruf: pendukung
     * mengetiknya sendiri, sering dengan huruf kecil atau diapit kalimat lain.
     */
    private function extractInvoiceNumber(string $pesan): ?string
    {
        if (preg_match('/\bINV-[0-9]{6,}-[A-Z0-9]{4,}\b/i', $pesan, $cocok)) {
            return strtoupper($cocok[0]);
        }

        return null;
    }
}
