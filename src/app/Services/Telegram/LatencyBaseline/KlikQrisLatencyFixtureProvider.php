<?php

namespace App\Services\Telegram\LatencyBaseline;

use App\Enums\KlikQrisEnvironment;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Support\DogfoodTiming;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Bounded local provider fixture for the latency baseline.
 *
 * It implements the real provider adapter contract so checkout, observation
 * and reconciliation owners run unchanged. It never performs HTTP: the
 * status delay is a recorded fixture, not a simulated business outcome.
 */
final class KlikQrisLatencyFixtureProvider implements KlikQrisProviderAdapter
{
    public int $statusDelayMs = 0;

    public ?CarbonImmutable $providerPaidAt = null;

    /** @var list<string> */
    public array $pendingMerchantRefs = [];

    public bool $failStatus = false;

    public bool $alwaysPending = false;

    public int $statusRequests = 0;

    public int $historyRequests = 0;

    /** @var array<string, true> */
    public array $observedHistory = [];

    public function __construct(
        private readonly string $signature = 'baseline-fixture-signature',
    ) {}

    public function createTransaction(Payment $payment): KlikQrisTransaction
    {
        return KlikQrisTransaction::fromCreatePayload(
            $this->payload($payment, 'PENDING'),
            KlikQrisEnvironment::Sandbox,
        );
    }

    public function transactionStatus(Payment $payment): KlikQrisTransaction
    {
        DogfoodTiming::log('dogfood.telegram.baseline_provider_started', [
            'correlation_id' => DogfoodTiming::correlation($payment->id),
            'started_at' => now()->toISOString(),
        ]);
        $this->delay();
        $this->statusRequests++;
        if (str_starts_with((string) $payment->merchant_ref, 'LATENCY-HISTORY-PAY-')) {
            $this->historyRequests++;
            $this->observedHistory[(string) $payment->merchant_ref] = true;
        }
        if ($this->failStatus) {
            throw new ConnectionException('Synthetic bounded provider outage');
        }
        DogfoodTiming::log('dogfood.telegram.baseline_provider_finished', [
            'correlation_id' => DogfoodTiming::correlation($payment->id),
            'finished_at' => now()->toISOString(),
        ]);

        return KlikQrisTransaction::fromStatusPayload(
            $this->payload($payment, $this->statusFor($payment)),
            KlikQrisEnvironment::Sandbox,
        );
    }

    private function statusFor(Payment $payment): string
    {
        return $this->alwaysPending || in_array((string) $payment->merchant_ref, $this->pendingMerchantRefs, true)
            ? 'PENDING'
            : 'SUCCESS';
    }

    /** @return array<string, mixed> */
    private function payload(Payment $payment, string $status): array
    {
        $paidAt = $this->providerPaidAt ?? CarbonImmutable::now()->subSeconds(2);

        return ['data' => array_filter([
            'order_id' => (string) $payment->merchant_ref,
            'amount' => (int) $payment->amount,
            'total_amount' => (int) $payment->amount + 16,
            'status' => $status,
            'qris_url' => 'https://klikqris.com/storage/sandbox/qris_baseline.png',
            'expired_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601ZuluString(),
            'paid_at' => $status === 'SUCCESS' ? $paidAt->toIso8601ZuluString() : null,
            'signature' => (string) ($payment->klikqris_transaction_signature ?: $this->signature),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function delay(): void
    {
        if ($this->statusDelayMs > 0) {
            usleep($this->statusDelayMs * 1000);
        }
    }
}
