<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Customer;
use App\Services\OrderLifecycle;
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
        private OrderLifecycle $orderStates,
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
            || preg_match('/^buyer:cancel-pending:([0-9a-fA-F-]+)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->data === 'status') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $order = $context->customer->orders()->latest()->first();
            $orderStatus = $order ? $this->locale->orderStatus($context->customer, $order->order_status) : null;
            $paymentStatus = $order ? $this->locale->paymentStatus($context->customer, $order->payment_status) : null;
            $this->bot->sendMessage($context->chatId, $order ? $this->text($context->customer, "📦 Status pesanan\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Pesanan: {$orderStatus}\n💳 Pembayaran: {$paymentStatus}", "📦 Order status\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Order: {$orderStatus}\n💳 Payment: {$paymentStatus}") : $this->text($context->customer, 'Belum ada pesanan nih.', 'You do not have any orders yet.'));

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
        $this->session->forget($context->customer);

        $order = $context->customer->orders()
            ->whereKey($orderId)
            ->where('order_status', OrderStatus::PendingPayment->value)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereDoesntHave('payments', fn ($query) => $query->whereNotNull('payment_proof_submitted_at'))
            ->first();

        if (! $order) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Pesanan sudah tidak bisa dibatalkan.', 'This order can no longer be cancelled.'));
            $this->bot->sendMessage($context->chatId, $this->text($context->customer, "Pesanan tidak ditemukan atau sudah tidak bisa dibatalkan.\n\nCek status terbaru lewat /status.", "Order not found or can no longer be cancelled.\n\nCheck the latest status with /status."));

            return;
        }

        try {
            $cancelled = $this->orderStates->applyPendingCancellation($order, requireNoPaymentProof: true)->order;
        } catch (InvalidOrderTransition) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Pesanan sudah tidak bisa dibatalkan.', 'This order can no longer be cancelled.'));
            $this->bot->sendMessage($context->chatId, $this->text($context->customer, "Pesanan sudah diproses atau tidak bisa dibatalkan lagi.\n\nCek status terbaru lewat /status.", "This order has been processed or can no longer be cancelled.\n\nCheck the latest status with /status."));

            return;
        }

        if ($context->messageId !== null) {
            try {
                $this->bot->deleteMessage($context->chatId, $context->messageId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Order dibatalkan dan stok sudah dilepas.', 'Order cancelled and stock released.'));
        $this->bot->sendMessage($context->chatId, $this->text($context->customer, "❌ Pesanan dibatalkan\nInvoice {$cancelled->invoice_number} dibatalkan dan stok sudah dilepas kembali.\n\nKalau mau pesan lagi, buka /produk ya.", "❌ Order cancelled\nInvoice {$cancelled->invoice_number} was cancelled and the stock was released.\n\nOpen /products if you would like to order again."));
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
