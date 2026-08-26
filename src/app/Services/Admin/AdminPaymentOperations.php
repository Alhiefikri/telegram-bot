<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Support\PaymentProvider;
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
        private readonly KlikQrisProviderAdapter $klikQrisClient,
        private readonly KlikQrisCallbackProcessor $klikQrisProcessor,
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
    ) {}

    public function canReconcile(Payment $payment): bool
    {
        if (! in_array($payment->status, self::RECONCILABLE_PAYMENT_STATUSES, true)
            || blank($payment->merchant_ref)) {
            return false;
        }

        return $payment->provider === PaymentProvider::KLIKQRIS
            && $this->klikQrisReadiness->checkout()->isReady();
    }

    public function reconcile(Payment $payment, ?User $user = null, ?string $ip = null): PaymentEvent
    {
        if (! $this->canReconcile($payment)) {
            throw new InvalidArgumentException('Only supported payment providers with provider identifiers can be reconciled.');
        }

        $event = $this->klikQrisProcessor->processReconciliationForPayment(
            $payment,
            $this->klikQrisClient->transactionStatus($payment),
        );
        $this->audit->log('payment.manually_reconciled', $payment, null, ['payment_event_id' => $event->id], $user, $ip);

        return $event;
    }
}
