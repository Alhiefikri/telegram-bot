<?php

namespace App\Jobs;

use App\Services\Telegram\ProductPriceChangeNotificationService;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductPriceChangeNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $eventId, public ?string $jobEnqueuedAt = null) {}

    public function handle(ProductPriceChangeNotificationService $notifications): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.product_price_change_job_picked_up', [
            'event_id' => $this->eventId,
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $notifications->processEvent($this->eventId);
        } finally {
            DogfoodTiming::log('dogfood.telegram.product_price_change_job_finished', DogfoodTiming::queryStats() + [
                'event_id' => $this->eventId,
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
