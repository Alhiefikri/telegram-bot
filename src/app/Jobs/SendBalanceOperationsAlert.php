<?php

namespace App\Jobs;

use App\Models\TelegramOutboundEffect;
use App\Services\Operations\BalanceOperationsAlertDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one durable balance operations alert effect. Queue dispatch is
 * only the wakeup; the effect row and the ledger protocol own the delivery
 * state and survive Redis loss.
 */
final class SendBalanceOperationsAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(BalanceOperationsAlertDelivery $delivery): void
    {
        $effect = TelegramOutboundEffect::find($this->effectId);
        if ($effect !== null) {
            $delivery->deliver($effect);
        }
    }
}
