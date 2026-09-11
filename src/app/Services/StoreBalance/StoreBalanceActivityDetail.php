<?php

namespace App\Services\StoreBalance;

final class StoreBalanceActivityDetail
{
    public function __construct(
        public readonly StoreBalanceActivityItem $item,
        public readonly ?StoreBalanceActivityItem $relatedItem = null,
    ) {}
}
