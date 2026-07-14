<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramChatPrivacyGuard
{
    private const PRIVATE_CHAT_TYPE = 'private';

    private const NON_PRIVATE_MESSAGE_PROMPT_ID = "🔒 Lanjut di chat pribadi ya\n━━━━━━━━━━━━━━\nDemi keamanan, detail order, pembayaran, dan data akun tidak dikirim ke group.\n\nBuka chat pribadi dengan bot untuk lanjut.";

    private const NON_PRIVATE_MESSAGE_PROMPT_EN = "🔒 Continue in a private chat\n━━━━━━━━━━━━━━\nFor security, order, payment, and account details are not sent to groups.\n\nOpen a private chat with the bot to continue.";

    private const NON_PRIVATE_CALLBACK_PROMPT_ID = 'Demi keamanan, lanjutkan di chat pribadi dengan bot ya.';

    private const NON_PRIVATE_CALLBACK_PROMPT_EN = 'For security, continue in a private chat with the bot.';

    public function __construct(private TelegramBotClient $bot, private TelegramLocale $locale) {}

    public function isPrivate(string $chatType): bool
    {
        return $chatType === self::PRIVATE_CHAT_TYPE;
    }

    public function denyMessage(Customer $customer, string $chatId): void
    {
        $this->bot->sendMessage($chatId, $this->locale->text($customer, self::NON_PRIVATE_MESSAGE_PROMPT_ID, self::NON_PRIVATE_MESSAGE_PROMPT_EN));
    }

    public function denyCallback(Customer $customer, string $callbackQueryId): void
    {
        $this->bot->answerCallbackQuery($callbackQueryId, $this->locale->text($customer, self::NON_PRIVATE_CALLBACK_PROMPT_ID, self::NON_PRIVATE_CALLBACK_PROMPT_EN));
    }
}
