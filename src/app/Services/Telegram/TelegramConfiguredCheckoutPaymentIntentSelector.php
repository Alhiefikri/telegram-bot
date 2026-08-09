<?php

namespace App\Services\Telegram;

final readonly class TelegramConfiguredCheckoutPaymentIntentSelector implements TelegramCheckoutPaymentIntentSelector
{
    public function __construct(private TelegramCheckoutIntents $checkoutIntents) {}

    public function select(bool $binance): TelegramCheckoutPaymentIntentSelection
    {
        $selection = $this->checkoutIntents->configuredProviderSelection($binance);

        return new TelegramCheckoutPaymentIntentSelection(
            requestedPaymentMode: $selection->requestedProvider,
            paymentMode: $selection->provider,
            paymentIntent: $selection->paymentIntent,
        );
    }
}
