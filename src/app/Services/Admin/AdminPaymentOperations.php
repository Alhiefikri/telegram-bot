<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use InvalidArgumentException;

class AdminPaymentOperations
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PakasirProviderAdapter $pakasirClient,
        private readonly PakasirCallbackProcessor $pakasirProcessor,
    ) {}

    public function canReconcile(Payment $payment): bool
    {
        return $payment->provider === 'pakasir'
            && $payment->status === PaymentStatus::Pending
            && filled($payment->merchant_ref)
            && filled(config('services.pakasir.slug'))
            && filled(config('services.pakasir.api_key'));
    }

    public function reconcile(Payment $payment, ?User $user = null, ?string $ip = null): PaymentEvent
    {
        if (! $this->canReconcile($payment)) {
            throw new InvalidArgumentException('Only supported pending payments with provider identifiers can be reconciled.');
        }

        $event = match ($payment->provider) {
            'pakasir' => $this->pakasirProcessor->processReconciliationForPayment($payment, $this->pakasirClient->transactionDetail($payment)),
            default => throw new InvalidArgumentException('Only supported pending payments with provider identifiers can be reconciled.'),
        };
        $this->audit->log('payment.manually_reconciled', $payment, null, ['payment_event_id' => $event->id], $user, $ip);

        return $event;
    }
}
