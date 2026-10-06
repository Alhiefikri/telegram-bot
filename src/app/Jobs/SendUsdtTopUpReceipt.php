<?php

namespace App\Jobs;

use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\UsdtTopUpOutbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendUsdtTopUpReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(UsdtTopUpOutbound $outbound): void
    {
        $effect = TelegramOutboundEffect::find($this->effectId);
        if ($effect !== null) {
            $outbound->deliver($effect);
        }
    }
}
