<?php

namespace App\Jobs;

use App\Models\TelegramOutboundEffect;
use App\Services\Suppliers\SupplierManualTakeoverNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendSupplierManualTakeoverNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(SupplierManualTakeoverNotice $notice): void
    {
        $effect = TelegramOutboundEffect::find($this->effectId);
        if ($effect !== null) {
            $notice->deliver($effect);
        }
    }
}
