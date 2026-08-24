<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class ProviderBoundCheckoutPaymentIntent implements CheckoutPaymentIntent
{
    public function __construct(
        private CheckoutPaymentIntent $intent,
        public string $configuredProvider,
        public int $providerVersion,
        public ?int $settingId,
    ) {}

    public function inner(): CheckoutPaymentIntent
    {
        return $this->intent;
    }

    public function provider(): string
    {
        return $this->intent->provider();
    }

    public function method(): string
    {
        return $this->intent->method();
    }

    public function orderCurrency(): string
    {
        return $this->intent->orderCurrency();
    }

    public function paymentAmount(Collection $items, Collection $products): int
    {
        return $this->intent->paymentAmount($items, $products);
    }

    public function paymentCurrency(): string
    {
        return $this->intent->paymentCurrency();
    }

    public function expiresAt(
        ?CarbonInterface $requested = null,
        ?ManualPaymentSetting $setting = null,
    ): CarbonInterface {
        return $this->intent->expiresAt($requested, $setting);
    }

    public function assertReady(
        Cart $cart,
        Collection $items,
        Collection $products,
        ?ManualPaymentSetting $setting = null,
    ): void {
        $this->intent->assertReady($cart, $items, $products, $setting);
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return $this->intent->postCreationBehavior();
    }
}
