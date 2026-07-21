<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductStockNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $productId,
        public readonly int $addedQuantity,
        public readonly ?int $availableBefore = null,
    ) {}

    public function handle(TelegramProductNotificationService $notifications): void
    {
        $product = Product::query()->find($this->productId);

        if ($product === null) {
            return;
        }

        $notifications->notifyStockAdded($product, $this->addedQuantity, $this->availableBefore);
    }
}
