<?php

namespace App\Services\Telegram;

final readonly class TelegramConfiguredCheckoutPaymentIntentSelector implements TelegramCheckoutPaymentIntentSelector
{
    public function __construct(private TelegramCheckoutIntents $checkoutIntents) {}

    public function select(string|bool $rail): TelegramCheckoutPaymentIntentSelection
    {
        $paymentRail = is_bool($rail)
            ? ($rail ? 'binance_pay_id' : 'qris')
            : $rail;
        $selection = $this->checkoutIntents->configuredProviderSelection($paymentRail);

        return new TelegramCheckoutPaymentIntentSelection(
            requestedPaymentMode: $selection->requestedProvider,
            paymentMode: $selection->provider,
            paymentIntent: $selection->paymentIntent,
        );
    }
}
