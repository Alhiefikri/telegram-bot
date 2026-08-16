<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCommunityGate;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;

class TelegramCommunityGateVerifyCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCommunityGate $communityGate,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::BeforePrivacy;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === TelegramCommunityGate::VERIFY_CALLBACK;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $this->session->forget($context->customer);
        if ($this->communityGate->verify($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
        } else {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'community_gate.not_joined'));
        }

        return true;
    }
}
