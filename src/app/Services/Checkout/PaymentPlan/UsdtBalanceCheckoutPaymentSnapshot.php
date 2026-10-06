<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Services\Checkout\UsdtBalanceCheckoutPaymentPreparation;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class UsdtBalanceCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        if ($provider !== PaymentProvider::USDT_BALANCE
            || $method !== UsdtBalanceCheckoutPaymentPreparation::METHOD
            || $paymentCurrency !== 'USDT'
            || $amount <= 0) {
            throw new InvalidArgumentException('USDT Balance payment requires a positive micro-USDT amount and internal rail identity.');
        }
    }
}
