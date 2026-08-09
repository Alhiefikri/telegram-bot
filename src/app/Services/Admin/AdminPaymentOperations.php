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
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
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
        private readonly PakasirProviderAdapter $pakasirClient,
        private readonly PakasirCallbackProcessor $pakasirProcessor,
        private readonly PakasirProviderReadiness $pakasirReadiness,
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

        return match ($payment->provider) {
            PaymentProvider::PAKASIR => $this->pakasirReadiness->verification()->isReady(),
            PaymentProvider::KLIKQRIS => $this->klikQrisReadiness->checkout()->isReady(),
            default => false,
        };
    }

    public function reconcile(Payment $payment, ?User $user = null, ?string $ip = null): PaymentEvent
    {
        if (! $this->canReconcile($payment)) {
            throw new InvalidArgumentException('Only supported payment providers with provider identifiers can be reconciled.');
        }

        $event = match ($payment->provider) {
            PaymentProvider::PAKASIR => $this->pakasirProcessor->processReconciliationForPayment($payment, $this->pakasirClient->transactionDetail($payment)),
            PaymentProvider::KLIKQRIS => $this->klikQrisProcessor->processReconciliationForPayment($payment, $this->klikQrisClient->transactionStatus($payment)),
            default => throw new InvalidArgumentException('Only supported payment providers with provider identifiers can be reconciled.'),
        };
        $this->audit->log('payment.manually_reconciled', $payment, null, ['payment_event_id' => $event->id], $user, $ip);

        return $event;
    }
}
