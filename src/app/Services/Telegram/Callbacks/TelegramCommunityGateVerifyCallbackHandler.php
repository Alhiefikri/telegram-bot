<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramChatPrivacyGuard;
use App\Services\Telegram\TelegramCommunityGate;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramStartIntentSession;

class TelegramCommunityGateVerifyCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramCatalogIntents $catalog,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
        private TelegramMenuIntents $menu,
        private TelegramStartIntentSession $startIntents,
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
        if (! $context->isPrivate()) {
            return false;
        }

        $this->session->forgetWithLock($context->customer);
        $afterAllowed = function () use ($context): void {
            $intent = $this->startIntents->pull($context->customer);
            if ($intent !== null && $this->catalog->openStartIntent($context->customer, $context->chatId, $intent)) {
                return;
            }

            $this->menu->presentHome($context->customer, $context->chatId);
        };
        if ($this->communityGate->verify(
            $context->customer,
            $context->chatId,
            $this->chatPrivacy->isPrivate($context->chatType),
            $afterAllowed,
        )) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
        } else {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'community_gate.not_joined'));
        }

        return true;
    }
}
