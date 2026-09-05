<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramCatalogQuerySession;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMenuIntents;

/**
 * Routes the non-secret navigation buttons attached to a paid receipt.
 *
 * The Order UUID is only a customer-bound stale-context check. Navigation
 * itself is delegated to the existing catalog and menu intent adapters so
 * this callback cannot become a second state machine.
 */
final class TelegramReceiptActionCallbackHandler implements TelegramCallbackHandler
{
    private const BUY_AGAIN_PATTERN = '/^receipt:buy_again:([0-9a-fA-F-]{36})$/D';

    private const MAIN_MENU_PATTERN = '/^receipt:main_menu:([0-9a-fA-F-]{36})$/D';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramCatalogIntents $catalog,
        private readonly TelegramMenuIntents $menu,
        private readonly TelegramIntentSession $session,
        private readonly TelegramCatalogQuerySession $catalogQueries,
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
        return preg_match(self::BUY_AGAIN_PATTERN, $context->data) === 1
            || preg_match(self::MAIN_MENU_PATTERN, $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match(self::BUY_AGAIN_PATTERN, $context->data, $matches) === 1) {
            $this->handleBuyAgain($context, $matches[1]);

            return true;
        }

        if (preg_match(self::MAIN_MENU_PATTERN, $context->data, $matches) === 1) {
            $this->handleMainMenu($context, $matches[1]);

            return true;
        }

        return false;
    }

    private function handleBuyAgain(TelegramCallbackContext $context, string $orderId): void
    {
        if (! $this->customerOwnedEligibleOrder($context, $orderId)) {
            $this->answerUnavailable($context);

            return;
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $this->clearInteractionState($context);
        $this->catalog->sendCategoryCatalog($context->customer, $context->chatId);
    }

    private function handleMainMenu(TelegramCallbackContext $context, string $orderId): void
    {
        if (! $this->customerOwnedEligibleOrder($context, $orderId)) {
            $this->answerUnavailable($context);

            return;
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $this->clearInteractionState($context);
        $this->menu->sendMainMenu($context->customer, $context->chatId, $context->isPrivate());
    }

    private function clearInteractionState(TelegramCallbackContext $context): void
    {
        $this->session->forgetWithLock($context->customer);
        $this->catalogQueries->forget($context->customer);
    }

    private function answerUnavailable(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->locale->translate($context->customer, 'receipt.actions.unavailable'),
        );
    }

    private function customerOwnedEligibleOrder(TelegramCallbackContext $context, string $orderId): bool
    {
        if ((string) $context->chatId !== trim((string) $context->customer->telegram_id)) {
            return false;
        }

        $order = Order::query()
            ->select(['id', 'customer_id', 'order_status', 'payment_status'])
            ->with(['payments:id,order_id,status,paid_at,created_at'])
            ->whereKey($orderId)
            ->where('customer_id', $context->customer->getKey())
            ->first();

        if (! $order instanceof Order
            || $order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            || $order->hasOpenLatePaymentReview()) {
            return false;
        }

        $payment = $order->payments
            ->sortByDesc(fn (Payment $candidate): array => [
                $candidate->created_at?->getTimestamp() ?? 0,
                (string) $candidate->getKey(),
            ])
            ->first();

        return $payment instanceof Payment
            && (string) $payment->order_id === (string) $order->getKey()
            && $payment->status === PaymentStatus::Paid
            && $payment->paid_at !== null;
    }
}
