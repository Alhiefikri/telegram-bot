<?php

namespace App\Services\Binance;

use App\Enums\OrderStatus;
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

final class BinancePaymentVerification
{
    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        private readonly BinancePayClient $client,
        private readonly BinanceTransactionHistoryReader $history,
        private readonly OrderLifecycle $orders,
        private readonly BinanceVerificationQueue $queue,
    ) {}

    public function enabled(): bool
    {
        return $this->client->configured();
    }

    public function status(Payment $payment): BinanceVerificationStatus
    {
        $mode = (string) $payment->binance_verification_mode;
        $withinGrace = $payment->binance_verification_grace_until?->isFuture() === true;
        $submitted = $mode === 'amount_unique' || filled($payment->binance_order_id);
        $automatic = $payment->binance_auto_verification_enabled === true;

        if ($payment->status === PaymentStatus::Paid) {
            return new BinanceVerificationStatus(BinanceVerificationOutcome::Confirmed, $mode, $automatic, $submitted, $withinGrace);
        }

        if (! $automatic || ! in_array($mode, ['amount_unique', 'order_id'], true)) {
            return new BinanceVerificationStatus(BinanceVerificationOutcome::NotConfigured, $mode, false, $submitted, $withinGrace);
        }
        $outcome = match ($payment->binance_verification_status) {
            'confirmed' => BinanceVerificationOutcome::Confirmed,
            'mismatch' => BinanceVerificationOutcome::Mismatch,
            'provider_error' => BinanceVerificationOutcome::ProviderError,
            'not_configured' => BinanceVerificationOutcome::NotConfigured,
            'checking' => BinanceVerificationOutcome::IncompleteWindow,
            'not_found' => BinanceVerificationOutcome::NotFound,
            default => BinanceVerificationOutcome::NotFound,
        };

        return new BinanceVerificationStatus($outcome, $mode, true, $submitted, $withinGrace);
    }

    /**
     * Verify a pending Binance Payment using either the persisted provider
     * evidence window or a history snapshot supplied by reconciliation.
     *
     * @param  list<array<string, mixed>>|null  $rawTransactions
     */
    public function verify(Payment $payment, ?array $rawTransactions = null, int $submissionVersion = 0): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        if (! in_array($payment->binance_verification_mode, ['amount_unique', 'order_id'], true)) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotConfigured);
        }

        try {
            $transactions = $rawTransactions ?? $this->fetchTransactionsSince($payment);
        } catch (BinanceHistoryIncompleteException) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::IncompleteWindow);
        } catch (BinanceProviderException $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: $exception->retryable);
        } catch (Throwable $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: false);
        }

        return $payment->binance_verification_mode === 'amount_unique'
            ? $this->matchAndConfirmLegacy($payment, $transactions)
            : $this->matchAndConfirmOrderId(
                $payment,
                $transactions,
                $submissionVersion > 0 ? $submissionVersion : (int) $payment->binance_order_id_submission_version,
            );
    }

    public function submitOrderId(string $orderId, string $candidate): BinanceVerificationSubmissionResult
    {
        $normalized = BinanceOrderId::normalize($candidate);
        if ($normalized === null) {
            return BinanceVerificationSubmissionResult::of(BinanceVerificationSubmissionOutcome::Invalid);
        }

        $result = DB::transaction(function () use ($orderId, $normalized): BinanceVerificationSubmissionResult {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = $order->payments()
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return BinanceVerificationSubmissionResult::of(BinanceVerificationSubmissionOutcome::Expired);
            }

            if (! $payment->binance_auto_verification_enabled || $payment->binance_verification_mode !== 'order_id') {
                return BinanceVerificationSubmissionResult::of(BinanceVerificationSubmissionOutcome::Manual);
            }

            $deadline = $payment->expired_at
                ?? $order->expires_at
                ?? $payment->created_at?->copy()->addMinutes(20)
                ?? now()->addMinutes(20);
            if (! $deadline->isFuture()) {
                return BinanceVerificationSubmissionResult::of(BinanceVerificationSubmissionOutcome::Expired);
            }

            if ($payment->binance_verification_grace_until?->isPast()) {
                return BinanceVerificationSubmissionResult::of(BinanceVerificationSubmissionOutcome::GraceExpired);
            }

            if (in_array($payment->binance_verification_status, ['checking', 'not_found', 'provider_error'], true)
                && $payment->binance_order_id === $normalized
                && (int) $payment->binance_order_id_submission_version > 0
                && $payment->binance_verification_grace_until?->isFuture()) {
                return BinanceVerificationSubmissionResult::of(
                    BinanceVerificationSubmissionOutcome::Accepted,
                    $payment->fresh(),
                    (int) $payment->binance_order_id_submission_version,
                    $payment->binance_verification_status === 'provider_error'
                        && $payment->binance_last_verified_at === null,
                );
            }

            if ((int) $payment->binance_order_id_attempts >= BinanceOrderId::MAX_ATTEMPTS) {
                return BinanceVerificationSubmissionResult::of(BinanceVerificationSubmissionOutcome::Limit);
            }

            $version = (int) $payment->binance_order_id_submission_version + 1;
            $graceUntil = $payment->binance_verification_grace_until;
            if ($graceUntil === null) {
                $graceUntil = $deadline->copy()->addMinutes(3);
            }

            $payment->update([
                'binance_order_id' => $normalized,
                'binance_order_id_attempts' => (int) $payment->binance_order_id_attempts + 1,
                'binance_order_id_submission_version' => $version,
                'binance_order_id_submitted_at' => now(),
                'binance_verification_grace_until' => $graceUntil,
                'binance_verification_status' => 'checking',
                'expired_at' => $payment->expired_at ?? $deadline,
            ]);

            return BinanceVerificationSubmissionResult::of(
                BinanceVerificationSubmissionOutcome::Accepted,
                $payment->fresh(),
                $version,
                true,
            );
        }, 5);

        if (! $result->accepted() || ! $result->dispatchRequired || $result->payment === null) {
            return $result;
        }

        try {
            $this->queue->dispatchOrderId($result->payment, $result->version);
        } catch (Throwable $exception) {
            report($exception);
            Payment::query()
                ->whereKey($result->payment->id)
                ->where('binance_order_id_submission_version', $result->version)
                ->update(['binance_verification_status' => 'provider_error']);

            return BinanceVerificationSubmissionResult::of(
                BinanceVerificationSubmissionOutcome::Accepted,
                $result->payment->fresh(),
                $result->version,
                true,
                true,
            );
        }

        return $result;
    }

    public function recordOutcome(Payment $payment, BinanceVerificationResult $result, int $submissionVersion = 0): void
    {
        if (in_array($result->outcome, [
            BinanceVerificationOutcome::StaleSubmission,
            BinanceVerificationOutcome::OrderNotPending,
        ], true)) {
            return;
        }

        $status = match ($result->outcome) {
            BinanceVerificationOutcome::Confirmed,
            BinanceVerificationOutcome::AlreadyPaid => 'confirmed',
            BinanceVerificationOutcome::NotFound => 'not_found',
            BinanceVerificationOutcome::IncompleteWindow => 'checking',
            BinanceVerificationOutcome::ProviderError => 'provider_error',
            BinanceVerificationOutcome::NotConfigured => 'not_configured',
            BinanceVerificationOutcome::Mismatch,
            BinanceVerificationOutcome::TransactionAlreadyUsed => 'mismatch',
            default => 'checking',
        };

        Payment::query()
            ->whereKey($payment->id)
            ->when(
                $submissionVersion > 0,
                fn ($query) => $query->where('binance_order_id_submission_version', $submissionVersion),
            )
            ->update([
                'binance_verification_status' => $status,
                'binance_last_verified_at' => now(),
            ]);
    }

    public function recordQueueFailure(Payment $payment, int $submissionVersion = 0, bool $closeGrace = false): BinanceVerificationResult
    {
        $query = Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Pending->value)
            ->when(
                $submissionVersion > 0,
                fn ($builder) => $builder->where('binance_order_id_submission_version', $submissionVersion),
            );

        $updates = [
            'binance_verification_status' => 'provider_error',
            'binance_last_verified_at' => now(),
        ];
        if ($closeGrace) {
            $updates['binance_verification_grace_until'] = now();
        }

        if ($query->update($updates) !== 1) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, retryable: false);
        }

        return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: false);
    }

    public function retryOrderId(Payment $payment, int $attemptNumber, int $retryNumber, int $submissionVersion): BinanceVerificationRetryDecision
    {
        $payment->loadMissing('order');
        $order = $payment->order;
        $orderId = trim((string) $payment->binance_order_id);

        if ($submissionVersion <= 0
            || $payment->binance_order_id_submission_version !== $submissionVersion
            || $orderId === '') {
            return BinanceVerificationRetryDecision::of(
                BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, retryable: false),
                terminal: true,
            );
        }

        if (! $this->retryEligible($payment, $order, $orderId, $attemptNumber, $submissionVersion)) {
            return BinanceVerificationRetryDecision::of($this->closeRetryWindow($payment, $submissionVersion), terminal: true);
        }

        $result = $this->verify($payment, null, $submissionVersion);
        $this->recordOutcome($payment, $result, $submissionVersion);

        if (in_array($result->outcome, [BinanceVerificationOutcome::NotConfigured, BinanceVerificationOutcome::OrderNotPending], true)
            || ($result->outcome === BinanceVerificationOutcome::ProviderError && ! $result->retryable)) {
            $this->closeProviderError($payment, $submissionVersion);

            return BinanceVerificationRetryDecision::of($result, terminal: true);
        }

        if (in_array($result->outcome, [BinanceVerificationOutcome::NotFound, BinanceVerificationOutcome::IncompleteWindow], true)
            || ($result->outcome === BinanceVerificationOutcome::ProviderError && $result->retryable)) {
            $delaySeconds = self::RETRY_DELAY_SECONDS * (2 ** max(0, $retryNumber - 1)) + random_int(0, 10);
            $remainingGraceSeconds = $payment->binance_verification_grace_until === null
                ? 0
                : max(0, $payment->binance_verification_grace_until->getTimestamp() - now()->getTimestamp());

            if ($payment->binance_verification_grace_until?->isFuture()
                && $retryNumber < 3
                && $delaySeconds + 5 <= $remainingGraceSeconds) {
                return BinanceVerificationRetryDecision::of($result, $delaySeconds);
            }

            $this->closeFinalRetry($payment, $submissionVersion, $result->outcome);

            return BinanceVerificationRetryDecision::of($result, terminal: true);
        }

        return BinanceVerificationRetryDecision::of($result, terminal: true);
    }

    private function retryEligible(Payment $payment, ?Order $order, string $orderId, int $attemptNumber, int $submissionVersion): bool
    {
        return $order !== null
            && $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->binance_verification_mode === 'order_id'
            && $payment->binance_order_id === $orderId
            && (int) $payment->binance_order_id_attempts === $attemptNumber
            && $submissionVersion > 0
            && (int) $payment->binance_order_id_submission_version === $submissionVersion
            && $payment->binance_verification_grace_until?->isFuture();
    }

    private function closeRetryWindow(Payment $payment, int $submissionVersion): BinanceVerificationResult
    {
        if ($payment->status === PaymentStatus::Paid) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::AlreadyPaid, retryable: false);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::OrderNotPending, retryable: false);
        }

        $outcome = $payment->binance_verification_status === 'not_found'
            ? BinanceVerificationOutcome::NotFound
            : BinanceVerificationOutcome::ProviderError;

        if ($payment->binance_verification_grace_until?->isPast()
            && in_array($payment->binance_verification_status, ['checking', 'not_found', 'provider_error'], true)) {
            Payment::query()
                ->whereKey($payment->id)
                ->where('binance_order_id_submission_version', $submissionVersion)
                ->update([
                    'binance_verification_status' => $outcome === BinanceVerificationOutcome::NotFound ? 'not_found' : 'provider_error',
                    'binance_verification_grace_until' => now(),
                    'binance_last_verified_at' => now(),
                ]);
            $payment->refresh();
        }

        return BinanceVerificationResult::of($outcome, retryable: false);
    }

    private function closeProviderError(Payment $payment, int $submissionVersion): void
    {
        Payment::query()
            ->whereKey($payment->id)
            ->where('binance_order_id_submission_version', $submissionVersion)
            ->update([
                'binance_verification_grace_until' => now(),
                'binance_verification_status' => 'provider_error',
                'binance_last_verified_at' => now(),
            ]);
        $payment->refresh();
    }

    private function closeFinalRetry(Payment $payment, int $submissionVersion, BinanceVerificationOutcome $outcome): void
    {
        Payment::query()
            ->whereKey($payment->id)
            ->where('binance_order_id_submission_version', $submissionVersion)
            ->update([
                'binance_verification_status' => $outcome === BinanceVerificationOutcome::NotFound ? 'not_found' : 'provider_error',
                'binance_verification_grace_until' => now(),
                'binance_last_verified_at' => now(),
            ]);
        $payment->refresh();
    }

    /** @param list<array<string, mixed>> $rawTransactions */
    private function matchAndConfirmOrderId(Payment $payment, array $rawTransactions, int $submissionVersion): BinanceVerificationResult
    {
        $transactions = collect($rawTransactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->values();

        $orderId = trim((string) $payment->binance_order_id);
        if ($orderId === '') {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        $matchingTransactions = $transactions
            ->filter(fn (BinancePayTransaction $transaction): string|false => $transaction->matchesSubmittedOrderId($orderId))
            ->values();
        $transaction = $matchingTransactions->first(
            fn (BinancePayTransaction $transaction): bool => $this->transactionMatchesPayment($transaction, $payment),
        );

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
        if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed
            && ! $this->recordMismatch($payment, $transaction, $submissionVersion)) {
            return BinanceVerificationResult::of(BinanceVerificationOutcome::StaleSubmission, $transaction);
        }

        return $result;
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
            return $this->confirm($payment, $transaction, orderIdMode: false);
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
                    || ! $this->transactionMatchesPayment($transaction, $locked)) {
                    return BinanceVerificationOutcome::StaleSubmission;
                }

                if ($locked->binance_verification_grace_until !== null
                    && $locked->binance_verification_grace_until->isPast()) {
                    $locked->update([
                        'provider_payload' => $transaction->sanitizedPayload(),
                        'binance_verification_status' => 'mismatch',
                        'binance_last_verified_at' => now(),
                    ]);

                    return BinanceVerificationOutcome::Mismatch;
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
