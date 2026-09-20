<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBroadcastIntents;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;

class TelegramBroadcastCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(private TelegramBroadcastIntents $broadcasts) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Privileged;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return str_starts_with($context->data, 'broadcast:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        return $this->broadcasts->handleCallback($context->customer, $context->chatId, $context->callbackQueryId, $context->data);
    }
}
