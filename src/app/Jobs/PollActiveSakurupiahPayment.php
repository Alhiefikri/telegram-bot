<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use App\Support\PaymentProvider;
use App\Support\QueueTopology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** A short-lived wakeup for an active checkout; the sweep repairs lost wakeups. */
final class PollActiveSakurupiahPayment implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INTERVAL_SECONDS = 20;

    public const JITTER_SECONDS = 4;

    private const MAX_DELAY_SECONDS = 86_400;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public int $timeout = 60;

    public function __construct(public readonly string $paymentId, int $uniqueFor = 120)
    {
        $this->onQueue(QueueTopology::PAYMENTS);
        $this->uniqueFor = $uniqueFor;
    }

    public function uniqueId(): string
    {
        return 'sakurupiah:active-poll:'.$this->paymentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [20, 60];
    }

    public static function schedule(Payment $payment): void
    {
        $connection = (string) config('queue.default');
        $driver = (string) config('queue.connections.'.$connection.'.driver');
        if ($connection === '' || $driver === '' || in_array($driver, ['sync', 'null'], true) || ! self::isActive($payment)) {
            return;
        }

        try {
            try {
                $retryAt = (new SakurupiahRequestBackpressure)->retryAt();
            } catch (Throwable) {
                $retryAt = null;
            }
            // Spread simultaneous checkouts across a short, stable window.
            $jitter = hexdec(substr(hash('sha256', (string) $payment->getKey()), 0, 4)) % (self::JITTER_SECONDS + 1);
            $baseDelay = max(self::INTERVAL_SECONDS, ($retryAt ?? 0) - now()->getTimestamp());
            $delay = min(self::MAX_DELAY_SECONDS, $baseDelay + $jitter);
            $deadline = collect([$payment->expired_at, $payment->provider_expires_at, $payment->order->expires_at])
                ->filter()->map(fn ($value) => $value->getTimestamp())->min();
            if ($deadline !== null) {
                $remaining = $deadline - now()->getTimestamp();
                if ($remaining <= 1 || ($retryAt !== null && $retryAt >= $deadline)) {
                    return;
                }
                // A final check just before the deadline still has a chance
                // to catch a payment when the ordinary interval extends past it.
                $delay = min($delay, $remaining - 1);
            }
            // The uniqueness lease must cover both the delay and queue wait.
            $pending = self::dispatch((string) $payment->getKey(), $delay + 120)
                ->onConnection($connection)
                ->delay(now()->addSeconds($delay))
                ->afterCommit();
            unset($pending);
        } catch (Throwable $exception) {
            // The minute sweep remains the durable recovery path.
            report($exception);
        }
    }

    public function handle(SakurupiahCallbackProcessor $processor): void
    {
        $payment = Payment::query()->with('order')->find($this->paymentId);
        if ($payment === null || ! self::isActive($payment)) {
            return;
        }

        ProviderVerificationProfiles::using(
            ProviderVerificationProfile::Interactive,
            fn () => $processor->reconcilePayment($payment, scheduled: true),
        );

        $payment->refresh()->load('order');
        self::schedule($payment);
    }

    private static function isActive(Payment $payment): bool
    {
        $payment->loadMissing('order');

        return $payment->provider === PaymentProvider::SAKURUPIAH
            && $payment->status === PaymentStatus::Pending
            && strtoupper(trim((string) $payment->method)) === SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS
            && strtoupper(trim((string) $payment->currency)) === 'IDR'
            && filled($payment->merchant_ref)
            && filled($payment->provider_reference)
            && $payment->order?->order_status === OrderStatus::PendingPayment
            && ($payment->expired_at === null || $payment->expired_at->isFuture())
            && ($payment->provider_expires_at === null || $payment->provider_expires_at->isFuture())
            && ($payment->order->expires_at === null || $payment->order->expires_at->isFuture());
    }
}
