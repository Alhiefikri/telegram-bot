<?php

namespace App\Jobs;

use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductStockNotifications implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $movementId, public ?string $jobEnqueuedAt = null) {}

    public function uniqueId(): string
    {
        return (string) $this->movementId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(mixed $pipeline = null): void
    {
        // Compatibility for callers/tests from the pre-recipient-pipeline seam.
        if (! $pipeline instanceof StockNotificationDeliveryPipeline) {
            $pipeline = app(StockNotificationDeliveryPipeline::class);
        }
        $result = $pipeline->deliver($this->movementId, StockNotificationDeliveryPipeline::JOB_BATCH_SIZE);
        if ($result['has_more']) {
            self::dispatch($this->movementId, now()->toISOString())
                ->onQueue(StockNotificationDeliveryPipeline::QUEUE)
                ->delay(now()->addSeconds($result['transient_failures'] > 0 ? 30 : 0));
        }
    }
}
