<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramChatPrivacyGuard;
use App\Services\Telegram\TelegramCommunityGate;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;

class TelegramLocaleCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramMenuIntents $menu,
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
        return preg_match('/^locale:(id|en)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! preg_match('/^locale:(id|en)$/', $context->data, $matches)) {
            return false;
        }

        $this->communityGate->clearPrompt($context->customer);
        $context->customer->update(['locale' => $matches[1]]);
        $context->customer->refresh();
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        if (! $this->communityGate->ensureAllowedOrPrompt($context->customer, $context->chatId)) {
            return true;
        }

        $this->menu->sendWelcome($context->customer, $context->chatId);
        $this->menu->sendMainMenu($context->customer, $context->chatId, $this->chatPrivacy->isPrivate($context->chatType));

        return true;
    }
}
