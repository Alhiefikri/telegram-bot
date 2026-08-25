<?php

namespace App\Services\Checkout;

/**
 * Compatibility adapter that snapshots the provider-setting identity selected
 * for a checkout. It does not assemble rail-specific Payment data.
 */
final readonly class ProviderBoundCheckoutPaymentIntent implements CheckoutPaymentIntent
{
    public function __construct(
        private CheckoutPaymentIntent $intent,
        public string $configuredProvider,
        public int $providerVersion,
        public ?int $settingId,
    ) {}

    public function provider(): string
    {
        return $this->intent->provider();
    }

    public function method(): string
    {
        return $this->intent->method();
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return $this->intent->postCreationBehavior();
    }
}
