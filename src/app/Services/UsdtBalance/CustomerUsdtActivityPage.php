<?php

namespace App\Services\UsdtBalance;

final class CustomerUsdtActivityPage
{
    /**
     * @param  list<CustomerUsdtActivityItem>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasOlder,
        public readonly bool $hasNewer,
        public readonly ?CustomerUsdtActivityCursor $olderCursor,
        public readonly ?CustomerUsdtActivityCursor $newerCursor,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
