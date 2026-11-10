<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\ManualStoreBalanceTopUpDecision;
use App\Models\StoreBalanceTopUp;
use App\Services\StoreBalance\TopUp\ManualStoreBalanceTopUpConfirmationService;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramManualPaymentIntents;
use Throwable;

class TelegramAdminPaymentCallbackHandler implements TelegramCallbackHandler
{
    private const PAYMENT_PATTERN = '/^admin:(approve-payment|reject-payment):([^\s:]+)$/';

    private const TOP_UP_PATTERN = '/^admin:(topup-approve|topup-reject):([1-9][0-9]{0,18})$/';

    public function __construct(
        private TelegramManualPaymentIntents $manualPayments,
        private ManualStoreBalanceTopUpConfirmationService $manualTopUps,
        private StoreBalanceTopUpOutbound $topUpOutbound,
        private TelegramAdminAccess $adminAccess,
        private TelegramBotClient $bot,
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
        return preg_match(self::PAYMENT_PATTERN, $context->data) === 1
            || preg_match(self::TOP_UP_PATTERN, $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match(self::TOP_UP_PATTERN, $context->data, $matches) === 1) {
            $this->handleManualTopUp($context, $matches[1], $matches[2]);

            return true;
        }

        if (! preg_match(self::PAYMENT_PATTERN, $context->data, $matches)) {
            return false;
        }

        $alternative = \App\Models\Payment::query()->where('order_id', $matches[2])
            ->whereNotNull('manual_alternative_for_payment_id')->first();
        if ($alternative !== null && (! $context->isPrivate()
            || ! $this->adminAccess->isAdmin($context->customer, $context->chatId)
            || $alternative->payment_proof_submitted_at === null
            || $context->messageId === null
            || ! \App\Models\TelegramOutboundEffect::query()
                ->where('source_type', \App\Models\Payment::class)->where('source_id', $alternative->id)
                ->where('effect_type', \App\Services\Telegram\TelegramOutboundEffectService::TYPE_MANUAL_PAYMENT_PROOF)
                ->where('state', 'sent')->where('telegram_chat_id', $context->chatId)
                ->where('telegram_message_id', (string) $context->messageId)->exists())) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Bukti pembayaran tidak cocok atau belum diterima.');

            return true;
        }

        if ($matches[1] === 'approve-payment') {
            $approved = $this->manualPayments->approveManualPaymentFromTelegram($context->customer, $context->chatId, $context->callbackQueryId, $matches[2]);
            if ($approved && $context->messageId !== null && $context->messageId > 0) {
                try {
                    $this->bot->deleteMessage($context->chatId, $context->messageId);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        } else {
            $this->manualPayments->rejectManualPaymentFromTelegram($context->customer, $context->chatId, $context->callbackQueryId, $matches[2]);
        }

        return true;
    }

    private function handleManualTopUp(TelegramCallbackContext $context, string $action, string $topUpId): void
    {
        if (! $this->adminAccess->isAdmin($context->customer, $context->chatId)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Hanya admin yang dapat mengonfirmasi top-up saldo.');

            return;
        }

        $topUp = StoreBalanceTopUp::query()->whereKey($topUpId)->first();
        if ($topUp === null) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Top-up tidak ditemukan.');

            return;
        }

        if ($topUp->manual_alternative_at !== null
            && (! $topUp->hasPaymentProof() || $context->messageId === null
                || ! \App\Models\TelegramOutboundEffect::query()
                    ->where('source_type', StoreBalanceTopUp::class)
                    ->where('source_id', (string) $topUp->getKey())
                    ->where('effect_type', \App\Services\Telegram\TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
                    ->where('state', \App\Enums\TelegramOutboundEffectState::Sent->value)
                    ->where('telegram_chat_id', $context->chatId)
                    ->where('telegram_message_id', (string) $context->messageId)
                    ->exists())) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, 'Bukti top-up tidak sesuai.');

            return;
        }

        $decision = $action === 'topup-approve'
            ? $this->manualTopUps->approve($topUp, $context->customer, $context->chatId)
            : $this->manualTopUps->reject($topUp, $context->customer, $context->chatId);

        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->decisionAnswer($decision),
        );

        if (! $decision->applied()) {
            return;
        }

        $fresh = $topUp->fresh();
        if ($fresh !== null) {
            $this->topUpOutbound->handoffReceipt($fresh);
        }

        if ($context->messageId !== null && $context->messageId > 0) {
            try {
                $this->bot->deleteMessage($context->chatId, $context->messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function decisionAnswer(ManualStoreBalanceTopUpDecision $decision): string
    {
        return match ($decision) {
            ManualStoreBalanceTopUpDecision::Confirmed => 'Top-up dikonfirmasi. Saldo pembeli sudah dikreditkan.',
            ManualStoreBalanceTopUpDecision::Rejected => 'Top-up ditolak. Tidak ada saldo yang dikreditkan.',
            ManualStoreBalanceTopUpDecision::AlreadyConfirmed,
            ManualStoreBalanceTopUpDecision::AlreadyRejected => 'Top-up ini sudah diputuskan sebelumnya.',
            ManualStoreBalanceTopUpDecision::Expired => 'Batas waktu top-up ini sudah lewat.',
            ManualStoreBalanceTopUpDecision::NotEligible => 'Top-up ini tidak bisa dikonfirmasi lagi.',
        };
    }
}
