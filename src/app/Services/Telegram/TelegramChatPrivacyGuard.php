<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramChatPrivacyGuard
{
    private const PRIVATE_CHAT_TYPE = 'private';

    public function __construct(private TelegramBotClient $bot, private TelegramLocale $locale) {}

    public function isPrivate(string $chatType): bool
    {
        return $chatType === self::PRIVATE_CHAT_TYPE;
    }

    public function denyMessage(Customer $customer, string $chatId): void
    {
        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'privacy.message'));
    }

    public function denyCallback(Customer $customer, string $callbackQueryId): void
    {
        $this->bot->answerCallbackQuery($callbackQueryId, $this->locale->translate($customer, 'privacy.callback'));
    }
}
