<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramCallbackRouter
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCallbackHandlerPipeline $handlers,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramLocale $locale,
        private TelegramUsdtFundingSession $usdtFundingSession,
        private TelegramContentIntents $content,
    ) {}

    public function route(Customer $customer, string $chatId, string $id, string $data, string $chatType = 'private', ?int $messageId = null): void
    {
        $context = new TelegramCallbackContext($customer, $chatId, $id, $data, $chatType, $messageId);
        // Any callback is navigation: a pending amount prompt must not
        // survive a flow change (the selected rail callback recreates it).
        $this->usdtFundingSession->forget($customer);
        if ($this->handlers->dispatch($context, TelegramCallbackStage::BeforePrivacy)) {
            return;
        }

        if (! $this->chatPrivacy->isPrivate($chatType) && ! $this->handlers->allowsNonPrivateChat($context)) {
            $this->chatPrivacy->denyCallback($customer, $id);

            return;
        }
        $this->content->forget($customer);

        if ($this->handlers->dispatch($context, TelegramCallbackStage::Privileged)) {
            return;
        }

        if ($this->handlers->dispatch($context, TelegramCallbackStage::Customer)) {
            return;
        }

        $this->bot->answerCallbackQuery($id, $this->locale->translate($customer, 'callback.unknown_action'));
    }
}
