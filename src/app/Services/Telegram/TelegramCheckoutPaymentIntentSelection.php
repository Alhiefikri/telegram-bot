<?php

namespace App\Services\Telegram;

use App\Services\Checkout\CheckoutPaymentIntent;

final readonly class TelegramCheckoutPaymentIntentSelection
{
    public function __construct(
        public string $requestedPaymentMode,
        public string $paymentMode,
        public CheckoutPaymentIntent $paymentIntent,
    ) {}
}
