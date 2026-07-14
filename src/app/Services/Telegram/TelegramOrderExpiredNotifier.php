<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Models\Order;

class TelegramOrderExpiredNotifier
{
    public function __construct(private readonly TelegramBotClient $bot, private readonly TelegramLocale $locale) {}

    public function send(Order $order): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $order->loadMissing(['customer', 'orderItems']);
        if (! $order->customer?->telegram_id || $order->order_status !== OrderStatus::Expired) {
            return;
        }

        $lines = [
            $this->locale->text($order->customer, '⏳ Order kedaluwarsa', '⏳ Order expired'),
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            $this->locale->text($order->customer, '📦 Produk: ', '📦 Products: ').$order->orderItems->map(fn ($item) => $item->product_name.($item->quantity > 1 ? ' x'.$item->quantity : ''))->join(', '),
            '💰 Total: Rp'.number_format($order->total_amount, 0, ',', '.'),
            '',
            $this->locale->text($order->customer, 'Pembayaran tidak selesai sampai batas waktu, jadi order dibatalkan otomatis.', 'Payment was not completed before the deadline, so this order was cancelled automatically.'),
            '',
            $this->locale->text($order->customer, 'Tenang, kamu masih bisa order ulang kapan aja.', 'You can place a new order anytime.'),
        ];

        $this->bot->sendMessage((string) $order->customer->telegram_id, implode("\n", $lines), [
            'inline_keyboard' => [[['text' => $this->locale->text($order->customer, '🛍️ Order Ulang', '🛍️ Order Again'), 'callback_data' => 'katalog']]],
        ]);
    }
}
