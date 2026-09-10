<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class StoreBalanceCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        if ($provider !== PaymentProvider::STORE_BALANCE
            || $method !== 'STORE_BALANCE'
            || $paymentCurrency !== 'IDR') {
            throw new InvalidArgumentException('Store Balance payment snapshot has an invalid rail identity.');
        }
    }
}
