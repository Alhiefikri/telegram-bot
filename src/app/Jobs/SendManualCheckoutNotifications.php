<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendManualCheckoutNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $orderId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->effectId === null ? $this->orderId : 'effect:'.$this->effectId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(TelegramCheckoutIntents $checkoutIntents, ?TelegramOutboundEffectService $outboundEffects = null): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.manual_checkout_notifications_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $outboundEffects ??= app(TelegramOutboundEffectService::class);
            $effect = $this->effectId === null
                ? $outboundEffects->forOrder($this->orderId, TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT)
                : TelegramOutboundEffect::query()
                    ->whereKey($this->effectId)
                    ->where('effect_type', TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT)
                    ->firstOrFail();

            if ($effect !== null) {
                // A legacy claim can represent a worker from the expand phase
                // that started before the effect ledger became authoritative.
                // Never race that worker with a new send.
                if ($this->effectId !== null || ! $outboundEffects->hasActiveLegacyClaim($effect)) {
                    $outboundEffects->processCheckout($effect, $checkoutIntents);
                }

                return;
            }

            $this->handleLegacy($checkoutIntents);
        } finally {
            DogfoodTiming::log('dogfood.telegram.manual_checkout_notifications_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }

    private function handleLegacy(TelegramCheckoutIntents $checkoutIntents): void
    {
        $order = Order::with(['customer', 'orderItems'])->findOrFail($this->orderId);
        $chatId = (string) $order->customer->telegram_id;

        $claimed = DB::transaction(function () use ($order): ?Order {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->manual_checkout_customer_notified_at !== null
                || $this->hasActiveClaim($locked->manual_checkout_customer_notification_started_at)) {
                return null;
            }

            $locked->forceFill(['manual_checkout_customer_notification_started_at' => now()])->save();

            return $locked->fresh(['customer', 'orderItems']);
        });
        if ($claimed === null) {
            return;
        }

        $order = $claimed;
        $chatId = (string) $order->customer->telegram_id;
        // This fallback is retained only for orders created before the
        // expand-phase effect ledger. New QR Manual checkouts always use the
        // token-checked ledger path above.
        try {
            DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => 'manual_payment_instructions',
                'payment_mode' => 'manual',
                'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                'order_correlation_id' => DogfoodTiming::correlation($order->id),
            ], fn () => $checkoutIntents->sendPaymentInstructions($chatId, $order));
        } catch (\Throwable $e) {
            $order->forceFill(['manual_checkout_customer_notification_started_at' => null])->save();

            throw $e;
        }

        $order->forceFill(['manual_checkout_customer_notified_at' => now(), 'manual_checkout_customer_notification_started_at' => null])->save();
    }

    private function hasActiveClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }
}
