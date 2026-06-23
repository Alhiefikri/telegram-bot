<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tripay\TripayCallbackProcessor;
use App\Services\Tripay\TripayClient;
use InvalidArgumentException;

class AdminPaymentOperations
{
    public function __construct(private readonly TripayClient $client, private readonly TripayCallbackProcessor $processor, private readonly AuditLogger $audit) {}

    public function canReconcile(Payment $payment): bool
    {
        return $payment->provider === 'tripay'
            && $payment->status === PaymentStatus::Pending
            && filled($payment->provider_reference);
    }

    public function reconcile(Payment $payment, ?User $user = null, ?string $ip = null): PaymentEvent
    {
        if (! $this->canReconcile($payment)) {
            throw new InvalidArgumentException('Only pending Tripay payments with a provider reference can be reconciled.');
        }

        $event = $this->processor->processReconciliationForPayment($payment, $this->client->detail($payment->provider_reference));
        $this->audit->log('payment.manually_reconciled', $payment, null, ['payment_event_id' => $event->id], $user, $ip);

        return $event;
    }
}
