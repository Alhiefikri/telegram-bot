<?php

namespace App\Services\Checkout;

use App\Support\PaymentProvider;

final readonly class TelegramStarsCheckoutIntent implements CheckoutPaymentIntent
{
    public const METHOD = 'TELEGRAM_STARS';

    public function __construct(
        public TelegramStarsCheckoutQuote $quote,
        public TelegramStarsConfirmation $confirmation,
    ) {}

    public function provider(): string
    {
        return PaymentProvider::TELEGRAM_STARS;
    }

    public function method(): string
    {
        return self::METHOD;
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::TelegramStarsPrepared;
    }
}
