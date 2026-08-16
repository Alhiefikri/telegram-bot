<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;

class TelegramPaymentProofCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(private TelegramBotClient $bot, private TelegramLocale $locale) {}

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
        return str_starts_with($context->data, 'payment-proof:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.proof.command_prompt'));

        return true;
    }
}
