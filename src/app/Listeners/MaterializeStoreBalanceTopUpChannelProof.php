<?php

namespace App\Listeners;

use App\Events\StoreBalanceTopUpSettled;
use App\Models\StoreBalanceTopUp;
use App\Services\Telegram\StoreBalanceTopUpOutbound;

final class MaterializeStoreBalanceTopUpChannelProof
{
    public function __construct(
        private readonly StoreBalanceTopUpOutbound $outbound,
    ) {}

    public function handle(StoreBalanceTopUpSettled $event): void
    {
        $topUp = StoreBalanceTopUp::query()->find($event->topUpId);
        if ($topUp === null) {
            return;
        }

        $this->outbound->handoffChannelProof($topUp);
    }
}
