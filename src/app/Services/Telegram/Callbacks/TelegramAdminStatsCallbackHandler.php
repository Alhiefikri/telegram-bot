<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramAdminStatsIntents;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;

class TelegramAdminStatsCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private TelegramAdminStatsIntents $adminStats,
        private TelegramLocale $locale,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::BeforeCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === 'statistik_harian';
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $this->adminAccess->isAdmin($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->text($context->customer, 'Akses ditolak.', 'Access denied.'));

            return true;
        }

        $this->adminStats->showDailyStats($context->customer, $context->chatId, $context->callbackQueryId);

        return true;
    }
}
