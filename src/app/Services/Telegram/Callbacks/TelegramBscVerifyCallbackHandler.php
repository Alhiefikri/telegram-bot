<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBscVerifyIntents;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;

final class TelegramBscVerifyCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(private readonly TelegramBscVerifyIntents $intents) {}

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
        return preg_match('/^bsc:(submit|check):[0-9a-fA-F-]+$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match('/^bsc:(submit|check):([0-9a-fA-F-]+)$/', $context->data, $matches) !== 1) {
            return false;
        }

        if ($matches[1] === 'submit') {
            $this->intents->handleSubmitTap($context->customer, $context->chatId, $context->callbackQueryId, $matches[2]);
        } else {
            $this->intents->handleCheckTap($context->customer, $context->chatId, $context->callbackQueryId, $matches[2]);
        }

        return true;
    }
}
