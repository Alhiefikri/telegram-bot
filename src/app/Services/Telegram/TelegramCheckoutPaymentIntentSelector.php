<?php

namespace App\Services\Telegram;

interface TelegramCheckoutPaymentIntentSelector
{
    public function select(string|bool $rail): TelegramCheckoutPaymentIntentSelection;
}
