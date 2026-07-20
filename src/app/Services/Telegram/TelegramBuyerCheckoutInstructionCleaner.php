<?php

namespace App\Services\Telegram;

use App\Models\Order;

class TelegramBuyerCheckoutInstructionCleaner
{
    public function __construct(private readonly TelegramBotClient $bot) {}

    public function deleteBinanceCheckoutInstruction(Order $order): void
    {
        $target = Order::query()
            ->with('payments')
            ->whereKey($order->id)
            ->first([
                'id',
                'manual_checkout_customer_notification_chat_id',
                'manual_checkout_customer_notification_message_id',
                'manual_checkout_customer_notification_deleted_at',
            ]);
        if (! $target
            || $target->payments->doesntContain(fn ($payment): bool => $payment->method === 'BINANCE_PAY_ID')
            || ! $target->manual_checkout_customer_notification_chat_id
            || ! $target->manual_checkout_customer_notification_message_id
            || $target->manual_checkout_customer_notification_deleted_at !== null) {
            return;
        }

        $this->bot->deleteMessage(
            $target->manual_checkout_customer_notification_chat_id,
            $target->manual_checkout_customer_notification_message_id,
        );

        Order::query()
            ->whereKey($target->id)
            ->where('manual_checkout_customer_notification_message_id', $target->manual_checkout_customer_notification_message_id)
            ->whereNull('manual_checkout_customer_notification_deleted_at')
            ->update(['manual_checkout_customer_notification_deleted_at' => now()]);
    }
}
