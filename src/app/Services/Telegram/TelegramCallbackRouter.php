<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramCallbackRouter
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCallbackHandlerPipeline $handlers,
        private TelegramIntentSession $session,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramLocale $locale,
    ) {}

    public function route(Customer $customer, string $chatId, string $id, string $data, string $chatType = 'private', ?int $messageId = null): void
    {
        $context = new TelegramCallbackContext($customer, $chatId, $id, $data, $chatType, $messageId);

        if ($this->handlers->dispatch($context, TelegramCallbackStage::BeforePrivacy)) {
            return;
        }

        if (! $this->chatPrivacy->isPrivate($chatType) && ! $this->handlers->allowsNonPrivateChat($context)) {
            $this->chatPrivacy->denyCallback($customer, $id);

            return;
        }

        if ($this->handlers->dispatch($context, TelegramCallbackStage::BeforeCommunityGate)) {
            return;
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
            $this->session->forget($customer);
            $this->bot->answerCallbackQuery($id, $this->locale->translate($customer, 'callback.community_gate_required'));

            return;
        }

        if ($this->handlers->dispatch($context, TelegramCallbackStage::AfterCommunityGate)) {
            return;
        }

        $this->bot->answerCallbackQuery($id, $this->locale->translate($customer, 'callback.unknown_action'));
    }
}
