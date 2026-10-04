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
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationPresenter;
use App\Services\Telegram\TelegramOrderIntents;

class TelegramOrderCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
        private TelegramMenuIntents $menu,
        private TelegramOrderIntents $orders,
        private TelegramNavigationPresenter $navigation,
        private CustomerPendingOrderCancellation $cancellation,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Customer;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === 'status'
            || $context->data === 'status:home'
            || preg_match('/\Aom:[0-9]{4}(0[1-9]|1[0-2])\z/D', $context->data) === 1
            || $context->data === 'orders:open'
            || preg_match('/^order:([^\s:]+)$/', $context->data) === 1
            || preg_match('/^buyer:cancel-pending:([0-9a-fA-F-]+)$/', $context->data) === 1
            || str_starts_with($context->data, 'txt:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (str_starts_with($context->data, 'txt:')) {
            return false;
        }

        if ($context->data === 'status:home' || str_starts_with($context->data, 'om:')) {
            $month = $this->orders->monthFromCallback($context->data);
            if ($context->data !== 'status:home' && $month === null) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);

                return true;
            }
            $this->session->forgetWithLock($context->customer);
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->navigation->presentOrEstablish(
                $context->customer,
                $context->chatId,
                $month === null ? $this->menu->homeSurface($context->customer, $context->chatId)
                    : $this->orders->orderHistorySurface($context->customer, month: $month),
            );

            return true;
        }

        if ($context->data === 'orders:open') {
            $this->session->forgetWithLock($context->customer);
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->navigation->presentOrEstablish(
                $context->customer,
                $context->chatId,
                $this->orders->orderHistorySurface($context->customer),
            );

            return true;
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

        $trackedMessageIds = [];
        if ((string) $cancelled->manual_checkout_customer_notification_chat_id === $context->chatId) {
            $trackedMessageIds[] = (int) $cancelled->manual_checkout_customer_notification_message_id;
        }
        if ((string) $cancelled->buyer_order_detail_message_chat_id === $context->chatId) {
            $trackedMessageIds[] = (int) $cancelled->buyer_order_detail_message_id;
        }
        if ($context->messageId !== null && ! in_array((int) $context->messageId, $trackedMessageIds, true)) {
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
