<?php

namespace App\Services\UsdtTopUps;

use App\Models\Customer;
use App\Models\CustomerUsdtTopUp;
use App\Models\UsdtBscRailInvoice;
use App\Services\Bsc\BscFinalReceiptInspection;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Carbon\CarbonImmutable;

interface UsdtTopUpRail
{
    public function readiness(Customer $customer): UsdtTopUpRailReadiness;

    public function createOrRecover(Customer $customer, string $reference, UsdtMicroAmount $amount, CarbonImmutable $expiresAt, ?CustomerUsdtTopUp $target = null): UsdtBscRailInvoice;

    public function inspect(Customer $customer, string $reference, string $transactionHash): BscFinalReceiptInspection;
}
