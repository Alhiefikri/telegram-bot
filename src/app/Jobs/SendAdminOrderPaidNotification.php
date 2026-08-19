<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\AdminOrderNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminOrderPaidNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

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

    public function handle(
        ?TelegramOutboundEffectService $outboundEffects = null,
        ?AdminOrderNotificationDeliveryPipeline $pipeline = null,
    ): void {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.admin_paid_order_notification_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $outboundEffects ??= app(TelegramOutboundEffectService::class);
            $effect = $this->effectId === null
                ? $outboundEffects->forOrder($this->orderId, TelegramOutboundEffectService::TYPE_ADMIN_PAID)
                : TelegramOutboundEffect::query()
                    ->whereKey($this->effectId)
                    ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_PAID)
                    ->firstOrFail();

            if ($effect !== null) {
                ($pipeline ??= app(AdminOrderNotificationDeliveryPipeline::class))->deliver($effect);
            } else {
                $effect = $outboundEffects->recordAdminOrderPaidNotification(Order::findOrFail($this->orderId));
                if ($effect !== null) {
                    ($pipeline ??= app(AdminOrderNotificationDeliveryPipeline::class))->deliver($effect);
                }
            }
        } finally {
            DogfoodTiming::log('dogfood.telegram.admin_paid_order_notification_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
