<?php

namespace App\Jobs;

use App\Services\ProductStockDeletionFakeSalesProofAggregator;
use App\Services\Telegram\FakeSalesProofPostPublisher;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PublishDeletedStockFakeSalesProof implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $productId,
        public readonly string $batchToken,
        public ?string $jobEnqueuedAt = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(
        ProductStockDeletionFakeSalesProofAggregator $aggregator,
        FakeSalesProofPostPublisher $publisher,
    ): void {
        $batch = $aggregator->claim($this->productId, $this->batchToken);
        if ($batch === null || $batch['quantity'] < 1) {
            return;
        }

        $complete = false;
        try {
            // The publisher owns channel settings, rendering, pricing, and
            // Telegram failure handling. This job never touches stock state.
            $result = $publisher->publishDeletedStock($this->productId, $batch['quantity']);
            if (($result['retryable'] ?? false) === true) {
                throw new \RuntimeException('Deleted stock Fake Sales Proof delivery failed; retrying.');
            }

            $complete = true;
        } finally {
            if ($batch['sold_out_movement_id'] !== null) {
                try {
                    SendProductStockNotifications::dispatch($batch['sold_out_movement_id'])
                        ->onQueue(StockNotificationDeliveryPipeline::QUEUE);
                } catch (\Throwable $exception) {
                    // Stock notification recovery owns the committed event if
                    // this after-proof handoff cannot be queued immediately.
                    report($exception);
                }
            }

            if ($complete) {
                $aggregator->complete($this->productId, $this->batchToken);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        app(ProductStockDeletionFakeSalesProofAggregator::class)
            ->complete($this->productId, $this->batchToken);
    }
}
