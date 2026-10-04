<?php

namespace App\Jobs;

use App\Models\TelegramOutboundEffect;
use App\Services\Suppliers\SupplierHealthDeliveryPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendSupplierHealthNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(SupplierHealthDeliveryPipeline $pipeline): void
    {
        $effect = TelegramOutboundEffect::find($this->effectId);
        if ($effect !== null) {
            $pipeline->deliver($effect);
        }
    }
}
