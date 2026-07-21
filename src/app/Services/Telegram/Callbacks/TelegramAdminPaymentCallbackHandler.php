<?php

namespace App\Services\Telegram\Callbacks;

use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramManualPaymentIntents;

class TelegramAdminPaymentCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(private TelegramManualPaymentIntents $manualPayments) {}

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
        return preg_match('/^admin:(approve-payment|reject-payment):([^\s:]+)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! preg_match('/^admin:(approve-payment|reject-payment):([^\s:]+)$/', $context->data, $matches)) {
            return false;
        }

        if ($matches[1] === 'approve-payment') {
            $this->manualPayments->approveManualPaymentFromTelegram($context->customer, $context->chatId, $context->callbackQueryId, $matches[2]);
        } else {
            $this->manualPayments->rejectManualPaymentFromTelegram($context->customer, $context->chatId, $context->callbackQueryId, $matches[2]);
        }

        return true;
    }
}
