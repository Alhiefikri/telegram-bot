<?php

namespace App\Services\Checkout;

final readonly class PaymentProviderSelection
{
    public function __construct(
        public string $requestedProvider,
        public string $provider,
        public string $pakasirCheckoutFlow,
        public int $providerVersion,
        public ?int $settingId,
        public CheckoutPaymentIntent $paymentIntent,
    ) {}
}
