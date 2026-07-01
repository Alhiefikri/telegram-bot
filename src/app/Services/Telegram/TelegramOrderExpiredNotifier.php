<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Models\Order;

class TelegramOrderExpiredNotifier
{
    public function __construct(private readonly TelegramBotClient $bot) {}

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
            '⏳ Order kedaluwarsa',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '📦 Produk: '.$order->orderItems->map(fn ($item) => $item->product_name.($item->quantity > 1 ? ' x'.$item->quantity : ''))->join(', '),
            '💰 Total: Rp'.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Pembayaran tidak selesai sampai batas waktu, jadi order dibatalkan otomatis.',
            '',
            'Tenang, kamu masih bisa order ulang kapan aja.',
        ];

        $this->bot->sendMessage((string) $order->customer->telegram_id, implode("\n", $lines), [
            'inline_keyboard' => [[['text' => '🛍️ Order Ulang', 'callback_data' => 'katalog']]],
        ]);
    }
}
