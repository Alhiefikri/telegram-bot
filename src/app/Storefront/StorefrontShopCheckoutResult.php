<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Pakasir\PakasirQrisTransaction;

final readonly class StorefrontShopCheckoutResult
{
    public function __construct(
        public Order $order,
        public Payment $payment,
        public PakasirQrisTransaction $transaction,
        public string $orderUrl,
        public string $statusUrl,
        public string $hostedPaymentUrl,
    ) {}
}
