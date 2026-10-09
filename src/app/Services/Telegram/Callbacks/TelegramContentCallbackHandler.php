<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramContentIntents;
use App\Services\Telegram\TelegramIconIntents;

final class TelegramContentCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(private TelegramBotClient $bot, private TelegramAdminAccess $access, private TelegramContentIntents $content, private TelegramIconIntents $icons) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Privileged;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return str_starts_with($context->data, 'content:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->chatType !== 'private' || ! $this->access->isAdmin($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Akses ditolak.');

            return true;
        }
        $this->icons->forgetPendingCapture($context->customer);
        $this->content->callback($context);

        return true;
    }
}
