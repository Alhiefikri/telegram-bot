<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationRootService;
use App\Services\Telegram\TelegramOrderIntents;

class TelegramHomeCallbackHandler implements TelegramCallbackHandler
{
    private const ACTIONS = ['menu:orders', 'menu:support', 'menu:language'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramMenuIntents $menu,
        private TelegramOrderIntents $orders,
        private TelegramLocale $locale,
        private TelegramNavigationRootService $roots,
    ) {}

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
        return in_array($context->data, self::ACTIONS, true);
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->supports($context)) {
            return false;
        }

        if ($this->roots->match($context->customer, $context->chatId, $context->messageId) === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'callback.navigation_stale'),
            );

            return true;
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId);

        if ($context->data === 'menu:orders') {
            $this->orders->sendOrderHistory($context->customer, $context->chatId);
        } elseif ($context->data === 'menu:support') {
            $this->menu->sendSupport($context->customer, $context->chatId);
        } else {
            $this->menu->sendLanguagePicker($context->chatId, $context->customer);
        }

        return true;
    }
}
