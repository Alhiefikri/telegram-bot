<?php

namespace App\Services;

use App\Jobs\ProcessProviderCancellationAttempt;
use App\Models\Payment;
use App\Models\ProviderCancellationAttempt;
use App\Services\Pakasir\PakasirPayloadSanitizer;
use App\Services\Pakasir\PakasirPaymentEventLogger;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class ProviderCancellationAttempts
{
    public function __construct(
        private PakasirProviderAdapter $pakasir,
        private PakasirPaymentEventLogger $events,
    ) {}

    /**
     * This method is called from the local release transaction. The attempt is
     * durable before its job becomes eligible to run after that transaction.
     */
    public function schedule(Payment $payment): void
    {
        if (! in_array($payment->provider, [PaymentProvider::PAKASIR, PaymentProvider::KLIKQRIS], true)) {
            return;
        }

        // A provider expiry is authoritative: there is no live transaction left to cancel.
        if ($payment->provider === PaymentProvider::PAKASIR
            && $payment->provider_expires_at !== null
            && ! $payment->provider_expires_at->isFuture()) {
            return;
        }

        $attempt = ProviderCancellationAttempt::query()->firstOrCreate(
            ['payment_id' => $payment->id],
            [
                'order_id' => $payment->order_id,
                'provider' => $payment->provider,
                'state' => $payment->provider === PaymentProvider::KLIKQRIS
                    ? ProviderCancellationAttempt::STATE_UNSUPPORTED
                    : ProviderCancellationAttempt::STATE_PENDING,
                'finished_at' => $payment->provider === PaymentProvider::KLIKQRIS ? now() : null,
            ],
        );

        if ($attempt->wasRecentlyCreated && $attempt->state === ProviderCancellationAttempt::STATE_PENDING) {
            ProcessProviderCancellationAttempt::dispatch($attempt->id)->onQueue('payments')->afterCommit();
        }
    }

    public function process(int $attemptId): void
    {
        $attempt = DB::transaction(function () use ($attemptId): ?ProviderCancellationAttempt {
            $locked = ProviderCancellationAttempt::query()->lockForUpdate()->find($attemptId);
            if ($locked === null || $this->isFinal($locked->state)) {
                return null;
            }

            $locked->update([
                'state' => ProviderCancellationAttempt::STATE_PROCESSING,
                'attempt_count' => $locked->attempt_count + 1,
                'started_at' => now(),
                'last_error' => null,
            ]);

            return $locked->fresh();
        }, attempts: 5);

        if ($attempt === null) {
            return;
        }

        $payment = Payment::query()->findOrFail($attempt->payment_id);

        try {
            $response = match ($attempt->provider) {
                PaymentProvider::PAKASIR => $this->pakasir->cancelTransaction($payment),
                default => throw new RuntimeException('Provider cancellation is unsupported.'),
            };
        } catch (Throwable $exception) {
            $this->markRetryableFailure($attempt->id, $exception);

            throw $exception;
        }

        $status = strtolower(trim((string) ($response['transaction']['status'] ?? $response['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            DB::transaction(function () use ($attempt, $payment, $response): void {
                $this->events->logProviderCancel($payment, $response);
                ProviderCancellationAttempt::query()->whereKey($attempt->id)->update([
                    'state' => ProviderCancellationAttempt::STATE_SUCCEEDED,
                    'provider_response' => PakasirPayloadSanitizer::sanitize($response),
                    'finished_at' => now(),
                ]);
            }, attempts: 5);

            return;
        }

        if (in_array($status, ['paid', 'completed', 'success'], true)) {
            // applyPaymentPaid re-locks Order then Payment and creates the
            // Manual Fulfillment Review because this Order is already released.
            app(OrderLifecycle::class)->applyPaymentPaid($payment);
            ProviderCancellationAttempt::query()->whereKey($attempt->id)->update([
                'state' => ProviderCancellationAttempt::STATE_ALREADY_PAID,
                'provider_response' => PakasirPayloadSanitizer::sanitize($response),
                'finished_at' => now(),
            ]);

            return;
        }

        $exception = new RuntimeException('Pakasir cancellation outcome is ambiguous'.($status === '' ? '.' : ': '.$status.'.'));
        $this->markRetryableFailure($attempt->id, $exception, $response);

        throw $exception;
    }

    public function markTerminalFailure(int $attemptId, Throwable $exception): void
    {
        ProviderCancellationAttempt::query()
            ->whereKey($attemptId)
            ->whereIn('state', [ProviderCancellationAttempt::STATE_PROCESSING, ProviderCancellationAttempt::STATE_RETRYABLE_FAILURE])
            ->update([
                'state' => ProviderCancellationAttempt::STATE_TERMINAL_FAILURE,
                'last_error' => $this->errorMessage($exception),
                'finished_at' => now(),
            ]);
    }

    /** @param array<string, mixed>|null $response */
    private function markRetryableFailure(int $attemptId, Throwable $exception, ?array $response = null): void
    {
        ProviderCancellationAttempt::query()->whereKey($attemptId)->update([
            'state' => ProviderCancellationAttempt::STATE_RETRYABLE_FAILURE,
            'provider_response' => $response === null ? null : PakasirPayloadSanitizer::sanitize($response),
            'last_error' => $this->errorMessage($exception),
        ]);
    }

    private function isFinal(string $state): bool
    {
        return in_array($state, [
            ProviderCancellationAttempt::STATE_SUCCEEDED,
            ProviderCancellationAttempt::STATE_ALREADY_PAID,
            ProviderCancellationAttempt::STATE_UNSUPPORTED,
            ProviderCancellationAttempt::STATE_TERMINAL_FAILURE,
        ], true);
    }

    private function errorMessage(Throwable $exception): string
    {
        return mb_substr(PakasirPayloadSanitizer::redactText($exception->getMessage()), 0, 4000);
    }
}
