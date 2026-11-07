<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class WijayaPayCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        if ($provider !== PaymentProvider::WIJAYAPAY || $method !== 'QRIS' || $paymentCurrency !== 'IDR') {
            throw new InvalidArgumentException('WijayaPay QRIS payment snapshot has an invalid rail identity.');
        }
    }
}
