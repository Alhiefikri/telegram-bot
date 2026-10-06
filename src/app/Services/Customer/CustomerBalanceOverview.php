<?php

namespace App\Services\Customer;

use App\Services\StoreBalance\StoreBalanceSnapshot;
use App\Services\UsdtBalance\UsdtBalanceSnapshot;

final class CustomerBalanceOverview
{
    public function __construct(
        public readonly StoreBalanceSnapshot $storeBalance,
        public readonly UsdtBalanceSnapshot $usdtBalance,
    ) {}
}
