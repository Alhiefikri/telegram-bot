<?php

namespace App\Services\Binance;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
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
     * Look for an incoming Binance Pay transfer that exactly matches the
     * payment's unique amount, then complete the order when one is found.
     */
    public function verifyPayment(Payment $payment): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
        }

        try {
            $transactions = $this->fetchTransactionsSince($payment);
        } catch (Throwable $exception) {
            report($exception);

            return BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError);
        }

        return $this->matchAndConfirm($payment, $transactions);
    }

    /**
     * Fallback path: the buyer pasted the Binance order id from their own
     * payment receipt. The transaction still has to match the invoice exactly.
     */
    public function verifyPaymentByProviderOrderId(Payment $payment, string $binanceOrderId): BinanceVerificationResult
    {
        $guard = $this->guard($payment);
        if ($guard !== null) {
            return $guard;
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
            return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $transaction);
        }

        return $this->confirm($payment, $transaction);
    }

    /**
     * Match a payment against an already-fetched transaction list. Used by the
     * scheduled reconciliation so one API call covers every pending payment.
     *
     * @param  list<array<string, mixed>>  $rawTransactions
     */
    public function matchAndConfirm(Payment $payment, array $rawTransactions): BinanceVerificationResult
    {
        $transactions = collect($rawTransactions)
            ->map(fn (array $payload): ?BinancePayTransaction => BinancePayTransaction::fromPayload($payload))
            ->filter()
            ->values();

        $candidates = $transactions
            ->filter(fn (BinancePayTransaction $transaction): bool => $this->transactionMatchesPayment($transaction, $payment))
            ->sortBy(fn (BinancePayTransaction $transaction): int => $transaction->transactionTimeMs())
            ->values();

        if ($candidates->isEmpty()) {
            $mismatch = $transactions->first(fn (BinancePayTransaction $transaction): bool => $transaction->isIncomingTo(trim((string) config('services.binance.pay_id')))
                && $transaction->amountMicro() === (int) $payment->amount);

            if ($mismatch !== null) {
                return BinanceVerificationResult::of(BinanceVerificationOutcome::Mismatch, $mismatch);
            }

            return BinanceVerificationResult::of(BinanceVerificationOutcome::NotFound);
        }

        $alreadyUsed = null;
        foreach ($candidates as $transaction) {
            $result = $this->confirm($payment, $transaction);

            if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed) {
                $alreadyUsed = $result;

                continue;
            }

            return $result;
        }

        return $alreadyUsed;
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
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === PaymentStatus::Paid) {
                    return BinanceVerificationOutcome::AlreadyPaid;
                }

                if ($locked->status !== PaymentStatus::Pending) {
                    return BinanceVerificationOutcome::OrderNotPending;
                }

                // The unique index on payments.provider_reference is the
                // double-claim guard: one Binance transaction can only ever
                // complete one order, even under concurrent verification.
                $locked->update([
                    'provider_reference' => $transaction->transactionId(),
                    'provider_payload' => $transaction->sanitizedPayload(),
                ]);

                $this->orders->applyManualCompletion($locked->order()->firstOrFail());

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

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
