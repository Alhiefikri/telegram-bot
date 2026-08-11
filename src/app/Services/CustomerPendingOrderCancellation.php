<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ReconcileCustomerCancellationPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirTransaction;
use App\Support\PaymentProvider;
use RuntimeException;
use Throwable;

final readonly class CustomerPendingOrderCancellation
{
    public function __construct(
        private OrderLifecycle $orders,
        private PakasirProviderAdapter $pakasir,
        private KlikQrisProviderAdapter $klikQris,
    ) {}

    public function cancel(Order $order): CustomerCancellationOutcome
    {
        $payment = $this->pendingPaymentFor($order);
        if ($payment === null) {
            return CustomerCancellationOutcome::NotCancellable;
        }

        if ($payment->payment_proof_submitted_at !== null) {
            return CustomerCancellationOutcome::NotCancellable;
        }

        try {
            if ($this->providerReportsPaid($payment)) {
                $this->orders->applyPaymentPaid($payment);

                return CustomerCancellationOutcome::Paid;
            }
        } catch (Throwable) {
            $this->scheduleReconciliation($payment);

            return CustomerCancellationOutcome::VerificationUnavailable;
        }

        try {
            $result = $this->orders->applyPendingCancellation($order, requireNoPaymentProof: true);
        } catch (InvalidOrderTransition) {
            return CustomerCancellationOutcome::NotCancellable;
        }

        return $result->newlyApplied
            ? CustomerCancellationOutcome::Cancelled
            : CustomerCancellationOutcome::NotCancellable;
    }

    /**
     * Reconciliation deliberately verifies the provider without cancelling the
     * Order. A provider-confirmed payment always goes through OrderLifecycle.
     */
    public function reconcile(Payment $payment): void
    {
        if (! $this->requiresProviderVerification($payment) || ! $this->providerReportsPaid($payment)) {
            return;
        }

        $this->orders->applyPaymentPaid($payment);
    }

    private function pendingPaymentFor(Order $order): ?Payment
    {
        if ($order->order_status !== OrderStatus::PendingPayment || $order->payment_status !== PaymentStatus::Pending) {
            return null;
        }

        return $order->payments()
            ->where('status', PaymentStatus::Pending)
            ->latest('id')
            ->first();
    }

    private function providerReportsPaid(Payment $payment): bool
    {
        return match ($payment->provider) {
            PaymentProvider::PAKASIR => $payment->isNativePakasirQris() && $this->pakasirReportsPaid($payment),
            PaymentProvider::KLIKQRIS => $this->klikQris->transactionStatus($payment)->isSuccess(),
            default => false,
        };
    }

    private function pakasirReportsPaid(Payment $payment): bool
    {
        $transaction = PakasirTransaction::fromPayload($this->pakasir->transactionDetail($payment));
        if ($transaction === null
            || $transaction->project() !== config('services.pakasir.slug')
            || $transaction->amount() !== $payment->amount
            || $transaction->orderId() !== $payment->merchant_ref
            || ($payment->provider_payment_method !== null && $transaction->paymentMethod() !== strtolower($payment->provider_payment_method))) {
            throw new RuntimeException('Pakasir verification mismatch.');
        }

        $status = strtolower(trim((string) $transaction->status()));
        if (! in_array($status, ['completed', 'pending', 'expired', 'cancelled'], true)) {
            throw new RuntimeException('Pakasir verification status is ambiguous.');
        }

        return $transaction->isCompleted();
    }

    private function requiresProviderVerification(Payment $payment): bool
    {
        return $payment->provider === PaymentProvider::KLIKQRIS
            || ($payment->provider === PaymentProvider::PAKASIR && $payment->isNativePakasirQris());
    }

    private function scheduleReconciliation(Payment $payment): void
    {
        if ($this->requiresProviderVerification($payment)) {
            ReconcileCustomerCancellationPayment::dispatchAfterResponse($payment->id);
        }
    }
}
