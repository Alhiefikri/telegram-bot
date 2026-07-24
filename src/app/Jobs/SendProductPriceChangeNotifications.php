<?php

namespace App\Jobs;

use App\Services\Telegram\ProductPriceChangeNotificationService;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductPriceChangeNotifications implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 300;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public readonly int $eventId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(ProductPriceChangeNotificationService $notifications): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        $result = null;
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.product_price_change_job_picked_up', [
            'event_id' => $this->eventId,
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $result = $notifications->processBatch($this->eventId);

            if ($result->hasMore) {
                self::dispatch($this->eventId, now()->toISOString())
                    ->onQueue('telegram-broadcast')
                    ->delay($result->transientFailures > 0 || $result->processed === 0 ? now()->addSeconds(30) : now());
            }
        } finally {
            DogfoodTiming::log('dogfood.telegram.product_price_change_job_finished', DogfoodTiming::queryStats() + [
                'event_id' => $this->eventId,
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'processed_recipients' => $result?->processed,
                'transient_failures' => $result?->transientFailures,
                'terminal_failures' => $result?->terminalFailures,
                'has_more' => $result?->hasMore,
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
