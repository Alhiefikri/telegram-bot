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
        private readonly BinanceTransactionHistoryReader $history,
        private readonly OrderLifecycle $orders,
    ) {}

    public function enabled(): bool
    {
        return $this->client->configured();
    }

    /**
     * Compatibility path for payments created before Order ID activation.
     * New payments must use verifyPaymentByProviderOrderId().
     */
    public function verifyPayment(Payment $payment): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        if ($payment->binance_verification_mode !== 'amount_unique') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
        }

        try {
            $transactions = $this->fetchTransactionsSince($payment);
        } catch (BinanceHistoryIncompleteException) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::IncompleteWindow);
        } catch (BinanceProviderException $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: $exception->retryable);
        } catch (Throwable $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: false);
        }

        return $this->matchAndConfirmLegacy($payment, $transactions);
    }

    /**
     * Verify the buyer-submitted Binance Order ID. The transaction still has
     * to match the invoice exactly.
     */
    public function verifyPaymentByProviderOrderId(Payment $payment, string $binanceOrderId, int $submissionVersion = 0): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        if ($payment->binance_verification_mode !== 'order_id') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
        }

        $binanceOrderId = BinanceOrderId::normalize($binanceOrderId) ?? trim($binanceOrderId);
        if ($binanceOrderId === '' || $payment->binance_order_id !== $binanceOrderId) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        try {
            $transactions = $this->fetchTransactionsSince($payment);
        } catch (BinanceHistoryIncompleteException $exception) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::IncompleteWindow);
        } catch (BinanceProviderException $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: $exception->retryable);
        } catch (Throwable $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: false);
        }

        $matchingTransactions = collect($transactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->filter(fn (BinancePayTransaction $transaction): string|false => $transaction->matchesSubmittedOrderId($binanceOrderId))
            ->values();

        $transaction = $matchingTransactions->first(fn (BinancePayTransaction $transaction): bool => $this->transactionMatchesPayment($transaction, $payment));

        if ($transaction === null) {
            $evidence = $matchingTransactions->first();
            if ($evidence === null) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
            }

            if (! $this->recordMismatch($payment, $evidence, $submissionVersion)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $evidence);
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $evidence);
        }

        if (! $this->transactionMatchesPayment($transaction, $payment)) {
            if (! $this->recordMismatch($payment, $transaction, $submissionVersion)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $transaction);
        }

        $result = $this->confirm($payment, $transaction, $submissionVersion);
        if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed) {
            if (! $this->recordMismatch($payment, $transaction, $submissionVersion)) {
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

        if ($payment->binance_verification_mode !== 'order_id') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
        }

        $transactions = collect($rawTransactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->values();

        $orderId = trim((string) $payment->binance_order_id);
        if ($orderId === '') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        $submissionVersion = (int) $payment->binance_order_id_submission_version;

        $matchingTransactions = $transactions
            ->filter(fn (BinancePayTransaction $transaction): string|false => $transaction->matchesSubmittedOrderId($orderId))
            ->values();
        $transaction = $matchingTransactions->first(fn (BinancePayTransaction $transaction): bool => $this->transactionMatchesPayment($transaction, $payment));
        if ($transaction === null) {
            $evidence = $matchingTransactions->first();
            if ($evidence === null) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
            }

            if (! $this->recordMismatch($payment, $evidence, $submissionVersion)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $evidence);
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $evidence);
        }

        $result = $this->confirm($payment, $transaction, $submissionVersion);
        if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed) {
            if (! $this->recordMismatch($payment, $transaction, $submissionVersion)) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rawTransactions */
    public function verifyLegacyPaymentFromTransactions(Payment $payment, array $rawTransactions): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        return $payment->binance_verification_mode === 'amount_unique'
            ? $this->matchAndConfirmLegacy($payment, $rawTransactions)
            : BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
    }

    /** @param list<array<string, mixed>> $rawTransactions */
    private function matchAndConfirmLegacy(Payment $payment, array $rawTransactions): BinanceVerificationResult
    {
        $transactions = collect($rawTransactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->values();

        $candidates = $transactions
            ->filter(fn (BinancePayTransaction $transaction): bool => $this->transactionMatchesPayment($transaction, $payment))
            ->sortBy(fn (BinancePayTransaction $transaction): int => $transaction->transactionTimeMs())
            ->values();

        foreach ($candidates as $transaction) {
            $result = $this->confirm($payment, $transaction, orderIdMode: false);
            if ($result->outcome !== BinanceVerificationOutcome::TransactionAlreadyUsed) {
                return $result;
            }
        }

        [$payId, $uid] = $this->receiverFor($payment);
        $mismatch = $transactions->first(fn (BinancePayTransaction $transaction): bool => $transaction->currency() === strtoupper((string) $payment->currency)
            && $transaction->amountMicro() === (int) $payment->amount
            && $transaction->isIncomingToConfiguredReceiver($payId, $uid));
        if ($mismatch !== null && $this->recordMismatch($payment, $mismatch)) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $mismatch);
        }

        return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
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
        $deadlineMs = $this->paymentDeadlineMs($payment);
        $createdMs = $payment->created_at?->getTimestampMs();
        if ($deadlineMs === null || $createdMs === null) {
            // A malformed legacy row must fail closed. Never query an open
            // ended history window that could accept a transaction outside
            // the order's lifetime.
            return [];
        }

        $startMs = $createdMs - $this->clockSkewMs();
        $endMs = $deadlineMs;
        $result = $this->history->read($startMs, $endMs);

        if (! $result->complete) {
            throw new BinanceHistoryIncompleteException('Binance history window was truncated before the read budget was exhausted.');
        }

        return $result->transactions;
    }

    private function transactionMatchesPayment(BinancePayTransaction $transaction, Payment $payment): bool
    {
        $deadlineMs = $this->paymentDeadlineMs($payment);
        $transactionTimeMs = $transaction->transactionTimeMs();
        $createdMs = $payment->created_at?->getTimestampMs();
        if ($deadlineMs === null || $createdMs === null) {
            return false;
        }

        [$payId, $uid] = $this->receiverFor($payment);

        return $transaction->isIncomingToConfiguredReceiver($payId, $uid)
            && $transaction->currency() === strtoupper((string) $payment->currency)
            && $transaction->amountMicro() === (int) $payment->amount
            && $transactionTimeMs >= $createdMs - $this->clockSkewMs()
            && $transactionTimeMs <= $deadlineMs
            && $transactionTimeMs <= now()->getTimestampMs() + $this->clockSkewMs();
    }

    private function paymentDeadlineMs(Payment $payment): ?int
    {
        $payment->loadMissing('order');

        return ($payment->expired_at ?? $payment->order?->expires_at)?->getTimestampMs();
    }

    private function confirm(Payment $payment, BinancePayTransaction $transaction, int $submissionVersion = 0, bool $orderIdMode = true): BinanceVerificationResult
    {
        try {
            $outcome = DB::transaction(function () use ($payment, $transaction, $submissionVersion, $orderIdMode): BinanceVerificationOutcome {
                $lockedOrder = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                $locked = $lockedOrder->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === PaymentStatus::Paid) {
                    return BinanceVerificationOutcome::AlreadyPaid;
                }

                if ($locked->status !== PaymentStatus::Pending) {
                    return BinanceVerificationOutcome::OrderNotPending;
                }

                if (! $locked->binance_auto_verification_enabled
                    || $locked->binance_verification_mode !== ($orderIdMode ? 'order_id' : 'amount_unique')
                    || ($orderIdMode && ($locked->binance_order_id !== $payment->binance_order_id
                        || ($submissionVersion > 0 && (int) $locked->binance_order_id_submission_version !== $submissionVersion)
                        || ! $transaction->matchesSubmittedOrderId((string) $locked->binance_order_id)))
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
                    'binance_verification_status' => 'confirmed',
                    'binance_last_verified_at' => now(),
                ]);

                $this->orders->applyManualCompletion($lockedOrder);

                $receivedAt = now();
                PaymentEvent::create([
                    'payment_id' => $locked->id,
                    'provider' => 'binance',
                    'event_type' => PaymentEventType::Reconciliation,
                    'merchant_ref' => $locked->merchant_ref,
                    'provider_reference' => BinancePayTransaction::maskIdentifier($transaction->transactionId()),
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

    private function recordMismatch(Payment $payment, BinancePayTransaction $transaction, int $submissionVersion = 0): bool
    {
        return Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Pending->value)
            ->when(filled($payment->binance_order_id), fn ($query) => $query->where('binance_order_id', (string) $payment->binance_order_id))
            ->when($submissionVersion > 0, fn ($query) => $query->where('binance_order_id_submission_version', $submissionVersion))
            ->update([
                'provider_payload' => $transaction->sanitizedPayload(),
                'binance_verification_grace_until' => now(),
                'binance_verification_status' => 'mismatch',
                'binance_last_verified_at' => now(),
            ]) === 1;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    /** @return array{0: ?string, 1: ?string} */
    private function receiverFor(Payment $payment): array
    {
        if ($payment->binance_receiver_type === 'pay_id') {
            return [$payment->binance_receiver_value, null];
        }

        if ($payment->binance_receiver_type === 'uid') {
            return [null, $payment->binance_receiver_value];
        }

        return [
            filled(config('services.binance.pay_id')) ? (string) config('services.binance.pay_id') : null,
            filled(config('services.binance.pay_uid')) ? (string) config('services.binance.pay_uid') : null,
        ];
    }
}
