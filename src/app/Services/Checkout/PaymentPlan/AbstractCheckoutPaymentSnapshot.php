<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

abstract readonly class AbstractCheckoutPaymentSnapshot implements CheckoutPaymentRailSnapshot
{
    public readonly CarbonImmutable $expiresAt;

    public function __construct(
        public string $provider,
        public string $method,
        public string $paymentCurrency,
        public int $amount,
        CarbonInterface $expiresAt,
    ) {
        if (trim($provider) === '' || trim($method) === '') {
            throw new InvalidArgumentException('A checkout payment snapshot requires a provider and method.');
        }

        if (trim($paymentCurrency) === '') {
            throw new InvalidArgumentException('A checkout payment snapshot requires a currency.');
        }

        if ($amount < 0) {
            throw new InvalidArgumentException('A checkout payment snapshot amount cannot be negative.');
        }

        $this->expiresAt = CarbonImmutable::instance($expiresAt);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function paymentCurrency(): string
    {
        return $this->paymentCurrency;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function persist(Payment $payment): void
    {
        // Most rails have no additional Payment columns in this snapshot.
    }
}
