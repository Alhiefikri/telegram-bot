<?php

namespace App\Services\Telegram;

use App\Services\Checkout\CheckoutPaymentIntents;

final readonly class TelegramConfiguredCheckoutPaymentIntentSelector implements TelegramCheckoutPaymentIntentSelector
{
    public function __construct(private TelegramCheckoutIntents $checkoutIntents) {}

    public function select(bool $binance): TelegramCheckoutPaymentIntentSelection
    {
        $selection = $this->checkoutIntents->providerSelection();
        $requestedPaymentMode = $selection['requested'];
        $paymentMode = $selection['effective'];

        if ($binance) {
            return new TelegramCheckoutPaymentIntentSelection(
                requestedPaymentMode: $requestedPaymentMode,
                paymentMode: $paymentMode,
                paymentIntent: CheckoutPaymentIntents::binancePay(),
            );
        }

        if ($paymentMode === 'klikqris') {
            return new TelegramCheckoutPaymentIntentSelection(
                requestedPaymentMode: $requestedPaymentMode,
                paymentMode: $paymentMode,
                paymentIntent: CheckoutPaymentIntents::klikQris(),
            );
        }

        if ($paymentMode === 'pakasir') {
            return new TelegramCheckoutPaymentIntentSelection(
                requestedPaymentMode: $requestedPaymentMode,
                paymentMode: $paymentMode,
                paymentIntent: CheckoutPaymentIntents::pakasir($this->checkoutIntents->pakasirPaymentMethod($selection['pakasir_checkout_flow'])),
            );
        }

        return new TelegramCheckoutPaymentIntentSelection(
            requestedPaymentMode: $requestedPaymentMode,
            paymentMode: $paymentMode,
            paymentIntent: CheckoutPaymentIntents::manualQris(),
        );
    }
}
