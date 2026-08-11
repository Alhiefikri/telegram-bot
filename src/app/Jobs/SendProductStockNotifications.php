<?php

namespace App\Jobs;

use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductStockNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $movementId) {}

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
        $result = $pipeline->deliver($this->movementId);
        if ($result['has_more']) {
            self::dispatch($this->movementId)->onQueue('notifications')->delay(now()->addSeconds($result['transient_failures'] > 0 ? 30 : 0));
        }
    }
}
