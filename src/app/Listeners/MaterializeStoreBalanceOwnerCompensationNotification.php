<?php

namespace App\Listeners;

use App\Events\StoreBalanceOwnerCompensated;
use App\Models\StoreBalanceCompensation;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;

final class MaterializeStoreBalanceOwnerCompensationNotification
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function handle(StoreBalanceOwnerCompensated $event): void
    {
        $compensation = StoreBalanceCompensation::query()
            ->with(['customer', 'topUp'])
            ->whereKey($event->compensationId)
            ->first();
        if ($compensation === null) {
            return;
        }

        $effect = $this->outboundEffects->recordOwnerCompensationNotification($compensation);
        if ($effect !== null) {
            $this->dispatcher->dispatch($effect);
        }
    }
}
