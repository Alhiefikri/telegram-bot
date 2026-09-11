<?php

namespace App\Listeners;

use App\Events\StoreBalanceTopUpSettled;
use App\Models\StoreBalanceTopUp;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;

final class MaterializeStoreBalanceTopUpReceipt
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function handle(StoreBalanceTopUpSettled $event): void
    {
        $topUp = StoreBalanceTopUp::query()
            ->with('customer')
            ->whereKey($event->topUpId)
            ->first();
        if ($topUp === null) {
            return;
        }

        $effect = $this->outboundEffects->recordBalanceTopUpReceipt($topUp);
        if ($effect !== null) {
            $this->dispatcher->dispatch($effect);
        }
    }
}
