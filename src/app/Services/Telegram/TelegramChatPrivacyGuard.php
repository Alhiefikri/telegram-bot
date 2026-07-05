<?php

namespace App\Services\Telegram;

class TelegramChatPrivacyGuard
{
    private const PRIVATE_CHAT_TYPE = 'private';

    private const NON_PRIVATE_MESSAGE_PROMPT = "🔒 Lanjut di chat pribadi ya\n━━━━━━━━━━━━━━\nDemi keamanan, detail order, pembayaran, dan data akun tidak dikirim ke group.\n\nBuka chat pribadi dengan bot untuk lanjut.";

    private const NON_PRIVATE_CALLBACK_PROMPT = 'Demi keamanan, lanjutkan di chat pribadi dengan bot ya.';

    public function __construct(private TelegramBotClient $bot) {}

    public function isPrivate(string $chatType): bool
    {
        return $chatType === self::PRIVATE_CHAT_TYPE;
    }

    public function denyMessage(string $chatId): void
    {
        $this->bot->sendMessage($chatId, self::NON_PRIVATE_MESSAGE_PROMPT);
    }

    public function denyCallback(string $callbackQueryId): void
    {
        $this->bot->answerCallbackQuery($callbackQueryId, self::NON_PRIVATE_CALLBACK_PROMPT);
    }
}
