<?php

namespace App\Services\Telegram;

interface TelegramCheckoutPaymentIntentSelector
{
    public function select(bool $binance): TelegramCheckoutPaymentIntentSelection;
}
