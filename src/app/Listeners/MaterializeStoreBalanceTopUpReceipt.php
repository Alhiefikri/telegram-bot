<?php

namespace App\Listeners;

use App\Events\StoreBalanceTopUpSettled;
use App\Jobs\DeleteStoreBalanceTopUpInstruction;
use App\Models\StoreBalanceTopUp;
use App\Services\Telegram\StoreBalanceTopUpOutbound;

final class MaterializeStoreBalanceTopUpReceipt
{
    public function __construct(
        private readonly StoreBalanceTopUpOutbound $storeBalanceTopUps,
    ) {}

    public function handle(StoreBalanceTopUpSettled $event): void
    {
        $topUp = StoreBalanceTopUp::query()
            ->whereKey($event->topUpId)
            ->first();
        if ($topUp === null) {
            return;
        }

        $this->storeBalanceTopUps->handoffReceipt($topUp);
        DeleteStoreBalanceTopUpInstruction::dispatch((string) $topUp->getKey())
            ->onQueue('notifications')
            ->afterCommit();
    }
}
