<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Services\CustomerCancellationOutcome;
use App\Services\CustomerPendingOrderCancellation;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOrderIntents;

class TelegramOrderCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
        private TelegramOrderIntents $orders,
        private CustomerPendingOrderCancellation $cancellation,
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
        return $context->data === 'status'
            || preg_match('/^order:([^\s:]+)$/', $context->data) === 1
            || preg_match('/^buyer:cancel-pending:([0-9a-fA-F-]+)$/', $context->data) === 1
            || str_starts_with($context->data, 'txt:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (str_starts_with($context->data, 'txt:')) {
            return false;
        }

        if ($context->data === 'status') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->orders->sendLatestOrderStatus($context->customer, $context->chatId);

            return true;
        }

        if (preg_match('/^order:([^\s:]+)$/', $context->data, $matches)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->orders->sendOrderDetail($context->customer, $context->chatId, $matches[1]);

            return true;
        }

        if (preg_match('/^buyer:cancel-pending:([0-9a-fA-F-]+)$/', $context->data, $matches)) {
            $this->cancelPendingOrder($context, $matches[1]);

            return true;
        }

        return false;
    }

    private function cancelPendingOrder(TelegramCallbackContext $context, string $orderId): void
    {
        $this->session->forgetWithLock($context->customer);

        $order = $context->customer->orders()
            ->whereKey($orderId)
            ->where('order_status', OrderStatus::PendingPayment->value)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereDoesntHave('payments', fn ($query) => $query->whereNotNull('payment_proof_submitted_at'))
            ->first();

        if (! $order) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.cancel.unavailable'));
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.cancel.not_found'));

            return;
        }

        $outcome = $this->cancellation->cancel($order);
        if ($outcome === CustomerCancellationOutcome::VerificationUnavailable) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.cancel.verification_unavailable'));
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.cancel.verification_unavailable_detail'));

            return;
        }

        if ($outcome === CustomerCancellationOutcome::Paid) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.cancel.paid'));
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.cancel.paid_detail'));

            return;
        }

        if ($outcome !== CustomerCancellationOutcome::Cancelled) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.cancel.processed'));
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.cancel.processed_detail'));

            return;
        }

        $cancelled = $order->fresh();

        if ($context->messageId !== null) {
            try {
                $this->bot->deleteMessage($context->chatId, $context->messageId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.cancel.success'));
        $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.cancel.success_detail', [
            'invoice' => $cancelled->invoice_number,
        ]));
    }
}
