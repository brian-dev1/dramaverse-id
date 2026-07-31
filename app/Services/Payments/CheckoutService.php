<?php

namespace App\Services\Payments;

use App\Support\Concerns\LogsPaymentEvents;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alur "pengguna menekan tombol bayar".
 *
 * Empat langkah, dalam urutan yang tidak boleh ditukar:
 *
 * 1. Invoice dibuat.
 * 2. Langganan PENDING dibuat, menunjuk invoice itu.
 * 3. Transaksi dibuat dengan `reference` milik kita — TERSIMPAN sebelum
 *    satu byte pun dikirim ke provider.
 * 4. Baru gateway dipanggil.
 *
 * Langkah 3 sebelum langkah 4 adalah keharusan, bukan kerapian: provider bisa
 * mengirim callback lebih cepat daripada jawaban charge-nya sampai kembali ke
 * kita. Kalau referensinya belum ada di basis data saat itu, callback yang sah
 * akan ditolak sebagai "referensi tidak dikenal", dan pengguna yang sudah
 * membayar tidak mendapatkan apa-apa.
 *
 * Langkah 2 ada supaya pengguna melihat "menunggu pembayaran" di riwayatnya,
 * bukan tidak melihat apa pun setelah menekan tombol bayar.
 */
class CheckoutService
{
    use LogsPaymentEvents;

    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected InvoiceService $invoices
    ) {
    }

    /**
     * Mulai pembayaran satu paket.
     *
     * @throws PaymentException
     */
    public function start(User $user, MembershipPlan $plan, PaymentProvider $provider): PaymentTransaction
    {
        if (! $plan->is_active) {
            throw PaymentException::planUnavailable();
        }

        $this->gateways->assertUsable($provider);

        /*
        |----------------------------------------------------------------------
        | Tiga baris, satu transaction
        |----------------------------------------------------------------------
        |
        | Invoice tanpa transaksi, atau transaksi tanpa langganan, adalah
        | keadaan yang tidak bisa ditafsirkan siapa pun saat menelusuri
        | sengketa. Ketiganya jadi atau tidak sama sekali.
        |
        | Panggilan ke gateway sengaja DI LUAR transaction: ia menyentuh
        | jaringan, dan menahan transaction database selama permintaan HTTP
        | berlangsung akan mengunci baris selama gateway lambat.
        |
        */

        [$invoice, $transaction] = DB::transaction(function () use ($user, $plan, $provider) {

            $invoice = $this->invoices->create($user, $plan, $provider);

            Subscription::create([
                'user_id'            => $user->id,
                'membership_plan_id' => $plan->id,
                'invoice_id'         => $invoice->id,
                'price'              => $invoice->subtotal,
                'status'             => SubscriptionStatus::PENDING->value,
                'source'             => 'checkout',
            ]);

            $transaction = PaymentTransaction::create([
                'invoice_id'          => $invoice->id,
                'payment_provider_id' => $provider->id,
                'reference'           => $this->reference($invoice->number),
                'amount'              => $invoice->total,
                'currency'            => $invoice->currency,
                'status'              => PaymentStatus::PENDING,
                'expires_at'          => $invoice->due_at,
            ]);

            return [$invoice, $transaction];
        });

        /*
        |----------------------------------------------------------------------
        | Baru panggil provider
        |----------------------------------------------------------------------
        */

        try {
            $charge = $this->gateways->for($provider)->charge($provider, $invoice, $transaction);

        } catch (Throwable $e) {

            // Kegagalan dicatat di barisnya, bukan cuma dilempar. Tanpa itu,
            // yang terlihat admin hanyalah transaksi PENDING tanpa sebab.
            $transaction->forceFill([
                'status'     => PaymentStatus::FAILED,
                'last_error' => $e->getMessage(),
            ])->save();

            $this->invoices->cancel($invoice, 'Gateway menolak: '.$e->getMessage());

            $this->log('error', 'checkout.failed', [
                'invoice'  => $invoice->number,
                'provider' => $provider->slug,
                'sebab'    => $e->getMessage(),
            ]);

            throw $e instanceof PaymentException
                ? $e
                : PaymentException::gatewayFailed($provider->name, $e->getMessage());
        }

        $transaction->forceFill([
            'external_id'      => $charge->externalId,
            'checkout_url'     => $charge->checkoutUrl,
            'status'           => $charge->status,
            'method'           => $charge->method,
            'response_payload' => $charge->raw,
            'expires_at'       => $charge->expiresAt ?? $transaction->expires_at,
        ])->save();

        $this->log('info', 'checkout.started', [
            'invoice'   => $invoice->number,
            'reference' => $transaction->reference,
            'provider'  => $provider->slug,
            'total'     => $invoice->total,
        ]);

        return $transaction->refresh();
    }

    /**
     * Coba bayar lagi tagihan yang sama dengan provider lain.
     *
     * Invoice-nya TIDAK dibuat ulang. Nomor tagihan sudah disebut pengguna ke
     * dukungan dan mungkin sudah dicatat di sisi mereka; menggantinya karena
     * percobaan pertama gagal membuat percakapan itu kehilangan acuan.
     *
     * @throws PaymentException
     */
    public function retry(Invoice $invoice, PaymentProvider $provider): PaymentTransaction
    {
        if (! $invoice->isPayable()) {
            throw PaymentException::notPayable($invoice->number);
        }

        $this->gateways->assertUsable($provider);

        // Biaya layanan bisa berbeda antar provider, jadi totalnya dihitung
        // ulang. Subtotal tidak disentuh: yang dibeli tetap paket yang sama
        // dengan harga yang sama.
        $fee = $provider->feeFor((float) $invoice->subtotal);

        $invoice->forceFill([
            'fee'   => $fee,
            'total' => (float) $invoice->subtotal + $fee,
        ])->save();

        $transaction = PaymentTransaction::create([
            'invoice_id'          => $invoice->id,
            'payment_provider_id' => $provider->id,
            'reference'           => $this->reference($invoice->number),
            'amount'              => $invoice->total,
            'currency'            => $invoice->currency,
            'status'              => PaymentStatus::PENDING,
            'expires_at'          => $invoice->due_at,
        ]);

        $charge = $this->gateways->for($provider)->charge($provider, $invoice, $transaction);

        $transaction->forceFill([
            'external_id'      => $charge->externalId,
            'checkout_url'     => $charge->checkoutUrl,
            'status'           => $charge->status,
            'method'           => $charge->method,
            'response_payload' => $charge->raw,
        ])->save();

        $this->log('info', 'checkout.retried', [
            'invoice'  => $invoice->number,
            'provider' => $provider->slug,
        ]);

        return $transaction->refresh();
    }

    /**
     * Referensi milik kita untuk satu percobaan.
     *
     * Nomor invoice + akhiran acak. Akhirannya perlu karena satu invoice bisa
     * punya beberapa percobaan, dan referensi yang sama untuk percobaan
     * berbeda membuat callback tidak bisa dipastikan milik yang mana.
     */
    private function reference(string $invoiceNumber): string
    {
        return $invoiceNumber.'-'.strtoupper(Str::random(4));
    }

}
