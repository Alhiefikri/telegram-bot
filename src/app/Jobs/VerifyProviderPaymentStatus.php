<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Telegram\TelegramKlikQrisRecoveryEscalator;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use App\Support\QueueTopology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class VerifyProviderPaymentStatus implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public int $timeout = 60;

    public function __construct(
        public readonly string $paymentId,
        public readonly string $customerId,
        public readonly string $provider,
        public readonly string $jobEnqueuedAt,
    ) {
        $this->onQueue(QueueTopology::PAYMENTS);
    }

    public function uniqueId(): string
    {
        return $this->paymentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(): void
    {
        $payment = Payment::query()->with('order.customer')->find($this->paymentId);
        if ($payment === null
            || $payment->provider !== $this->provider
            || (string) $payment->order?->customer_id !== $this->customerId) {
            return;
        }

        DogfoodTiming::log('dogfood.telegram.provider_status_job_picked_up', [
            'payment_correlation_id' => DogfoodTiming::correlation($this->paymentId),
            'payment_provider' => $this->provider,
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        ProviderVerificationProfiles::using(ProviderVerificationProfile::Reconciliation, function () use ($payment): void {
            if ($this->provider === PaymentProvider::KLIKQRIS) {
                $nativeQris = app(KlikQrisNativeQrisService::class);
                if (! $nativeQris->isCustomerStatusRefreshable($payment)
                    || ! app(KlikQrisProviderReadiness::class)->checkout()->isReady()) {
                    return;
                }

                $presentationPending = $payment->status === PaymentStatus::Pending
                    && ! $nativeQris->classify($payment)->isPayable();
                try {
                    $result = $nativeQris->reconcile($payment, function (Payment $payment, KlikQrisTransaction $transaction): void {
                        app(KlikQrisCallbackProcessor::class)->processReconciliationForPayment($payment, $transaction);
                    });
                } catch (Throwable $exception) {
                    app(TelegramKlikQrisRecoveryEscalator::class)->notifyFor($payment);
                    throw $exception;
                }
                if ($result !== null) {
                    app(TelegramKlikQrisRecoveryEscalator::class)->notifyFor($payment);
                }

                $payment->refresh();
                if ($presentationPending && $nativeQris->classify($payment)->isPayable()) {
                    $effect = app(TelegramOutboundEffectService::class)->recordKlikQrisPresentationRecovery(
                        $payment->order->fresh(), $payment,
                    );
                    if ($effect !== null) {
                        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
                    }
                }

                return;
            }

            if ($this->provider === PaymentProvider::PAKASIR
                && $payment->status === PaymentStatus::Pending
                && $payment->order->order_status === OrderStatus::PendingPayment
                && filled($payment->merchant_ref)
                && app(PakasirProviderReadiness::class)->verification()->isReady()) {
                app(PakasirCallbackProcessor::class)->processReconciliationForPayment(
                    $payment, app(PakasirProviderAdapter::class)->transactionDetail($payment),
                );
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
