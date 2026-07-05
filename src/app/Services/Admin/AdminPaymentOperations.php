<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use InvalidArgumentException;

class AdminPaymentOperations
{
    private const RECONCILABLE_PAYMENT_STATUSES = [
        PaymentStatus::Pending,
        PaymentStatus::Expired,
        PaymentStatus::Failed,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PakasirProviderAdapter $pakasirClient,
        private readonly PakasirCallbackProcessor $pakasirProcessor,
        private readonly PakasirProviderReadiness $pakasirReadiness,
    ) {}

    public function canReconcile(Payment $payment): bool
    {
        return $payment->provider === 'pakasir'
            && in_array($payment->status, self::RECONCILABLE_PAYMENT_STATUSES, true)
            && filled($payment->merchant_ref)
            && $this->pakasirReadiness->verification()->isReady();
    }

    public function reconcile(Payment $payment, ?User $user = null, ?string $ip = null): PaymentEvent
    {
        if (! $this->canReconcile($payment)) {
            throw new InvalidArgumentException('Only supported Pakasir payments with provider identifiers can be reconciled.');
        }

        $event = match ($payment->provider) {
            'pakasir' => $this->pakasirProcessor->processReconciliationForPayment($payment, $this->pakasirClient->transactionDetail($payment)),
            default => throw new InvalidArgumentException('Only supported Pakasir payments with provider identifiers can be reconciled.'),
        };
        $this->audit->log('payment.manually_reconciled', $payment, null, ['payment_event_id' => $event->id], $user, $ip);

        return $event;
    }
}
