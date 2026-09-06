<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\ReceiptActionPanelDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendTelegramReceiptActionPanel implements ShouldBeUnique, ShouldQueue
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

    public function handle(ReceiptActionPanelDeliveryPipeline $pipeline): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        $rssBefore = DogfoodTiming::residentMemoryBytes();
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.receipt_actions_job_picked_up', [
            'correlation_id' => DogfoodTiming::correlation($this->orderId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $effect = TelegramOutboundEffect::query()
                ->where('source_type', Order::class)
                ->where('source_id', $this->orderId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
                ->when($this->effectId !== null, fn ($query) => $query->whereKey($this->effectId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($effect !== null) {
                $pipeline->deliver($effect, $this->jobEnqueuedAt);
            }
        } finally {
            $rssAfter = DogfoodTiming::residentMemoryBytes();
            DogfoodTiming::log('dogfood.telegram.receipt_actions_job_finished', DogfoodTiming::queryStats() + [
                'correlation_id' => DogfoodTiming::correlation($this->orderId),
                'effect_correlation_id' => DogfoodTiming::correlation($this->effectId),
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
                'worker_rss_before_bytes' => $rssBefore,
                'worker_rss_after_bytes' => $rssAfter,
                'worker_rss_delta_bytes' => max(0, $rssAfter - $rssBefore),
            ]);
        }
    }
}
