<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBinanceVerifyIntents;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;

class TelegramBinanceVerifyCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(private TelegramBinanceVerifyIntents $intents) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return preg_match('/^binance:(verify|orderid):([0-9a-fA-F-]+)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match('/^binance:(verify|orderid):([0-9a-fA-F-]+)$/', $context->data, $matches) !== 1) {
            return false;
        }

        [, $action, $orderId] = $matches;

        if ($action === 'verify') {
            $this->intents->handleVerifyTap($context->customer, $context->chatId, $context->callbackQueryId, $orderId);
        } else {
            $this->intents->handleOrderIdPromptTap($context->customer, $context->chatId, $context->callbackQueryId, $orderId);
        }

        return true;
    }
}
