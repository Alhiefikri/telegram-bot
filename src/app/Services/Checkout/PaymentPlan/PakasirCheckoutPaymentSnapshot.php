<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class PakasirCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
        public string $flow,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        if ($provider !== PaymentProvider::PAKASIR
            || ! PakasirCheckoutFlow::isAllowed($flow)
            || $method !== $flow
            || $paymentCurrency !== 'IDR') {
            throw new InvalidArgumentException('Pakasir payment snapshot has an invalid rail identity.');
        }
    }

    public function checkoutFlow(): string
    {
        return $this->flow;
    }
}
