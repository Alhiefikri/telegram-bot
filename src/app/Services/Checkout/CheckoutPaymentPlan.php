<?php

namespace App\Services\Checkout;

use App\Models\Payment;
use App\Services\Checkout\PaymentPlan\BinancePayCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\BscCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\CheckoutPaymentRailSnapshot;
use App\Services\Checkout\PaymentPlan\KlikQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CheckoutPaymentPlan
{
    public readonly CarbonImmutable $orderExpiresAt;

    public readonly CarbonImmutable $paymentExpiresAt;

    public function __construct(
        public string $orderCurrency,
        CarbonInterface $orderExpiresAt,
        public string $provider,
        public string $method,
        public string $paymentCurrency,
        public int $amount,
        CarbonInterface $paymentExpiresAt,
        public CheckoutPaymentRailSnapshot $railSnapshot,
        public CheckoutPostCreationBehavior $postCreationBehavior,
    ) {
        if (trim($orderCurrency) === '' || trim($provider) === '' || trim($method) === '') {
            throw new InvalidArgumentException('A checkout payment plan requires its order and payment identities.');
        }

        if (trim($paymentCurrency) === '') {
            throw new InvalidArgumentException('A checkout payment plan requires a payment currency.');
        }

        if ($amount < 0) {
            throw new InvalidArgumentException('A checkout payment plan amount cannot be negative.');
        }

        $this->orderExpiresAt = CarbonImmutable::instance($orderExpiresAt);
        $this->paymentExpiresAt = CarbonImmutable::instance($paymentExpiresAt);

        if ($this->railSnapshot->provider() !== $provider
            || $this->railSnapshot->method() !== $method
            || $this->railSnapshot->paymentCurrency() !== $paymentCurrency
            || $this->railSnapshot->amount() !== $amount
            || ! $this->railSnapshot->expiresAt()->equalTo($this->paymentExpiresAt)) {
            throw new InvalidArgumentException('Checkout payment plan and rail snapshot do not match.');
        }

        $expectedSnapshot = match (CheckoutPaymentPreparationIdentity::key($provider, $method)) {
            CheckoutPaymentPreparationIdentity::key('manual', 'QR_MANUAL') => ManualQrisCheckoutPaymentSnapshot::class,
            CheckoutPaymentPreparationIdentity::key('klikqris', 'QRIS') => KlikQrisCheckoutPaymentSnapshot::class,
            CheckoutPaymentPreparationIdentity::key('manual', 'BINANCE_PAY_ID') => BinancePayCheckoutPaymentSnapshot::class,
            CheckoutPaymentPreparationIdentity::key('manual', 'USDT_BEP20') => BscCheckoutPaymentSnapshot::class,
            default => null,
        };

        if ($expectedSnapshot === null || ! $this->railSnapshot instanceof $expectedSnapshot) {
            throw new InvalidArgumentException('Checkout payment plan has an invalid typed rail snapshot.');
        }
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->paymentExpiresAt;
    }

    public function orderCurrency(): string
    {
        return $this->orderCurrency;
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

    public function orderExpiresAt(): CarbonImmutable
    {
        return $this->orderExpiresAt;
    }

    public function paymentExpiresAt(): CarbonImmutable
    {
        return $this->paymentExpiresAt;
    }

    public function rail(): CheckoutPaymentRailSnapshot
    {
        return $this->railSnapshot;
    }

    public function railSnapshot(): CheckoutPaymentRailSnapshot
    {
        return $this->railSnapshot;
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return $this->postCreationBehavior;
    }

    public function persistRailExtension(Payment $payment): void
    {
        $this->railSnapshot->persist($payment);
    }
}
