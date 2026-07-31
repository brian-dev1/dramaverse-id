<?php

namespace App\Support\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Penulisan log berawalan `payment.` dengan penghormatan pada sakelar config.
 *
 * Diangkat ke trait di Phase 12: lima kelas di lapisan pembayaran —
 * MembershipService, CheckoutService, InvoiceService, PaymentCallbackService,
 * dan AbstractGateway — berisi method `log()` yang isinya sama persis.
 *
 * Lima salinan berarti lima tempat yang harus diubah saat sakelar,
 * channel, atau awalannya berubah, dan yang terlewat akan diam-diam
 * berhenti mencatat.
 */
trait LogsPaymentEvents
{
    /**
     * @param  string  $event  tanpa awalan `payment.`
     */
    protected function log(string $level, string $event, array $context = []): void
    {
        if (! config('payment.logging.enabled', true)) {
            return;
        }

        Log::channel(config('payment.logging.channel') ?: config('logging.default'))
            ->log($level, 'payment.'.$event, $context);
    }
}
