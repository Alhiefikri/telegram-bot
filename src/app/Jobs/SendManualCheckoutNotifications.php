<?php

namespace App\Jobs;

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
                $outboundEffects->processCheckout($effect, $checkoutIntents);

                return;
            }

            $effect = $outboundEffects->materializeCheckoutEffectForOrder(
                $this->orderId,
                TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
            );
            if ($effect !== null) {
                $outboundEffects->processCheckout($effect, $checkoutIntents);
            }
        } finally {
            DogfoodTiming::log('dogfood.telegram.manual_checkout_notifications_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }

}
