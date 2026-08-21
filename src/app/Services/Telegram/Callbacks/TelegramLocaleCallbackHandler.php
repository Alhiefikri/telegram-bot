<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramChatPrivacyGuard;
use App\Services\Telegram\TelegramCommunityGate;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;

class TelegramLocaleCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramMenuIntents $menu,
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
        return $this->locale->localeFromCallbackData($context->data) !== null;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $locale = $this->locale->localeFromCallbackData($context->data);
        if ($locale === null) {
            return false;
        }

        $this->communityGate->clearPrompt($context->customer);
        $this->session->forget($context->customer);
        $context->customer->update(['locale' => $locale]);
        $context->customer->refresh();
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        if (! $this->communityGate->ensureAllowedOrPrompt($context->customer, $context->chatId)) {
            return true;
        }

        $this->menu->sendWelcome($context->customer, $context->chatId, $this->chatPrivacy->isPrivate($context->chatType));

        return true;
    }
}
