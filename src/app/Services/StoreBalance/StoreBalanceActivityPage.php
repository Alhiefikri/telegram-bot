<?php

namespace App\Services\StoreBalance;

final class StoreBalanceActivityPage
{
    /**
     * @param  list<StoreBalanceActivityItem>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasOlder,
        public readonly bool $hasNewer,
        public readonly ?StoreBalanceActivityCursor $olderCursor,
        public readonly ?StoreBalanceActivityCursor $newerCursor,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
