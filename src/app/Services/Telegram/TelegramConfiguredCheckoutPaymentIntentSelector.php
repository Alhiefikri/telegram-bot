<?php

namespace App\Services\Telegram;

use App\Services\Checkout\CheckoutPaymentIntents;

final readonly class TelegramConfiguredCheckoutPaymentIntentSelector implements TelegramCheckoutPaymentIntentSelector
{
    public function __construct(private TelegramCheckoutIntents $checkoutIntents) {}

    public function select(bool $binance): TelegramCheckoutPaymentIntentSelection
    {
        $requestedPaymentMode = $this->checkoutIntents->requestedPaymentMode();
        $paymentMode = $this->checkoutIntents->paymentMode();

        if ($binance) {
            return new TelegramCheckoutPaymentIntentSelection(
                requestedPaymentMode: $requestedPaymentMode,
                paymentMode: $paymentMode,
                paymentIntent: CheckoutPaymentIntents::binancePay(),
            );
        }

        if ($paymentMode === 'pakasir') {
            return new TelegramCheckoutPaymentIntentSelection(
                requestedPaymentMode: $requestedPaymentMode,
                paymentMode: $paymentMode,
                paymentIntent: CheckoutPaymentIntents::pakasir($this->checkoutIntents->pakasirPaymentMethod()),
            );
        }

        return new TelegramCheckoutPaymentIntentSelection(
            requestedPaymentMode: $requestedPaymentMode,
            paymentMode: $paymentMode,
            paymentIntent: CheckoutPaymentIntents::manualQris(),
        );
    }
}
