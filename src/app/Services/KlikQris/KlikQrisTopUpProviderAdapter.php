<?php

namespace App\Services\KlikQris;

use App\Models\StoreBalanceTopUp;

interface KlikQrisTopUpProviderAdapter
{
    public function createTopUpTransaction(StoreBalanceTopUp $topUp): KlikQrisTransaction;

    public function topUpTransactionStatus(StoreBalanceTopUp $topUp): KlikQrisTransaction;
}
