<?php

namespace App\Services\Binance;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class BinanceAutoVerification
{
    public function __construct(
        private readonly BinancePayClient $client,
        private readonly OrderLifecycle $orders,
    ) {}

    public function enabled(): bool
    {
        return $this->client->configured();
    }

    /**
     * Verify the buyer-submitted Binance Order ID. The transaction still has
     * to match the invoice exactly.
     */
    public function verifyPaymentByProviderOrderId(Payment $payment, string $binanceOrderId): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        $binanceOrderId = trim($binanceOrderId);
        if ($binanceOrderId === '' || $payment->binance_order_id !== $binanceOrderId) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        try {
            $transactions = $this->fetchTransactionsSince($payment);
        } catch (Throwable $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError);
        }

        $transaction = collect($transactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->first(fn (BinancePayTransaction $transaction): bool => $transaction->orderId() === $binanceOrderId);

        if ($transaction === null) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        if (! $this->transactionMatchesPayment($transaction, $payment)) {
            if (! $this->recordMismatch($payment, $transaction)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $transaction);
        }

        $result = $this->confirm($payment, $transaction);
        if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed) {
            if (! $this->recordMismatch($payment, $transaction)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
            }
        }

        return $result;
    }

    /**
     * Match a payment against an already-fetched transaction list. Used by the
     * scheduled reconciliation so one API call covers every pending payment.
     *
     * @param  list<array<string, mixed>>  $rawTransactions
     */
    public function matchAndConfirm(Payment $payment, array $rawTransactions): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        $transactions = collect($rawTransactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->values();

        $orderId = trim((string) $payment->binance_order_id);
        if ($orderId === '') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        $transaction = $transactions->first(fn (BinancePayTransaction $transaction): bool => $transaction->orderId() === $orderId);
        if ($transaction === null) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        if (! $this->transactionMatchesPayment($transaction, $payment)) {
            if (! $this->recordMismatch($payment, $transaction)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $transaction);
        }

        $result = $this->confirm($payment, $transaction);
        if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed) {
            if (! $this->recordMismatch($payment, $transaction)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
            }
        }

        return $result;
    }

    private function guard(Payment $payment): ?BinanceVerificationResult
    {
        if (! $this->enabled()) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
        }

        if ($payment->provider !== 'manual' || $payment->method !== 'BINANCE_PAY_ID') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::OrderNotPending);
        }

        $payment->refresh();

        if (! $payment->binance_auto_verification_enabled) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
        }

        if ($payment->status === PaymentStatus::Paid) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::AlreadyPaid);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::OrderNotPending);
        }

        if ($payment->binance_verification_grace_until !== null && $payment->binance_verification_grace_until->isPast()) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::OrderNotPending);
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function fetchTransactionsSince(Payment $payment): array
    {
        $startMs = $payment->created_at->getTimestampMs() - $this->clockSkewMs();

        return $this->client->payTransactions($startMs);
    }

    private function transactionMatchesPayment(BinancePayTransaction $transaction, Payment $payment): bool
    {
        return $transaction->isIncomingTo(trim((string) config('services.binance.pay_id')))
            && $transaction->currency() === strtoupper((string) $payment->currency)
            && $transaction->amountMicro() === (int) $payment->amount
            && $transaction->transactionTimeMs() >= $payment->created_at->getTimestampMs() - $this->clockSkewMs();
    }

    private function confirm(Payment $payment, BinancePayTransaction $transaction): BinanceVerificationResult
    {
        try {
            $outcome = DB::transaction(function () use ($payment, $transaction): BinanceVerificationOutcome {
                $lockedOrder = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                $locked = $lockedOrder->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === PaymentStatus::Paid) {
                    return BinanceVerificationOutcome::AlreadyPaid;
                }

                if ($locked->status !== PaymentStatus::Pending) {
                    return BinanceVerificationOutcome::OrderNotPending;
                }

                if (! $locked->binance_auto_verification_enabled
                    || $locked->binance_order_id !== $payment->binance_order_id
                    || $locked->binance_order_id !== $transaction->orderId()
                    || ($locked->binance_verification_grace_until !== null && $locked->binance_verification_grace_until->isPast())
                    || ! $this->transactionMatchesPayment($transaction, $locked)) {
                    return BinanceVerificationOutcome::StaleSubmission;
                }

                // The unique index on payments.provider_reference is the
                // double-claim guard: one Binance transaction can only ever
                // complete one order, even under concurrent verification.
                $locked->update([
                    'provider_reference' => $transaction->transactionId(),
                    'provider_payload' => $transaction->sanitizedPayload(),
                ]);

                $this->orders->applyManualCompletion($lockedOrder);

                $receivedAt = now();
                PaymentEvent::create([
                    'payment_id' => $locked->id,
                    'provider' => 'binance',
                    'event_type' => PaymentEventType::Reconciliation,
                    'merchant_ref' => $locked->merchant_ref,
                    'provider_reference' => $transaction->transactionId(),
                    'provider_status' => 'SUCCESS',
                    'raw_payload' => $transaction->sanitizedPayload(),
                    'received_at' => $receivedAt,
                    'last_received_at' => $receivedAt,
                    'received_count' => 1,
                    'processed_at' => $receivedAt,
                ]);

                return BinanceVerificationOutcome::Confirmed;
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::TransactionAlreadyUsed, $transaction);
        } catch (InvalidOrderTransition) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::OrderNotPending, $transaction);
        }

        return BinanceVerificationResult::of($outcome, $transaction);
    }

    private function clockSkewMs(): int
    {
        return max(0, (int) config('services.binance.verify_clock_skew_seconds', 120)) * 1000;
    }

    private function recordMismatch(Payment $payment, BinancePayTransaction $transaction): bool
    {
        return Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('binance_order_id', (string) $payment->binance_order_id)
            ->update([
                'provider_payload' => $transaction->sanitizedPayload(),
                'binance_verification_grace_until' => now(),
            ]) === 1;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
