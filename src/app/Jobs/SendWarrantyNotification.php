<?php

namespace App\Jobs;

use App\Models\TelegramOutboundEffect;
use App\Services\Warranty\WarrantyNotificationPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendWarrantyNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(WarrantyNotificationPipeline $notifications): void
    {
        $effect = TelegramOutboundEffect::find($this->effectId);
        if ($effect !== null) {
            $notifications->deliver($effect);
        }
    }
}
