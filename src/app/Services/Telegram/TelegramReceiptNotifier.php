<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramReceiptNotifier
{
    public function __construct(private TelegramBotClient $bot) {}

    public function send(Order $order, bool $force = false): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $lock = Cache::lock('telegram-receipt:'.$order->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $claimed = DB::transaction(function () use ($order, $force) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->payment_status !== PaymentStatus::Paid || (! $force && $locked->telegram_receipt_sent_at !== null)) {
                    return null;
                }

                return $locked;
            });

            if (! $claimed) {
                return;
            }

            $claimed->loadMissing(['customer', 'orderItems']);
            if (! $claimed->customer?->telegram_id) {
                return;
            }

            $lines = [
                '✅ Pembayaran diterima',
                '━━━━━━━━━━━━━━',
                '🧾 Invoice: '.$claimed->invoice_number,
                '',
                'Produk pesanan kamu:',
            ];
            foreach ($claimed->orderItems as $item) {
                $lines[] = '- '.$item->product_name.' x '.$item->quantity;
            }
            $lines[] = '';
            $lines[] = 'Terima kasih. Simpan pesan ini sebagai bukti pembelian.';

            $this->bot->sendMessage($claimed->customer->telegram_id, implode("\n", $lines));
            $receiptSentAt = Order::whereKey($claimed->id);
            if (! $force) {
                $receiptSentAt->whereNull('telegram_receipt_sent_at');
            }
            $receiptSentAt->update(['telegram_receipt_sent_at' => now()]);
        } finally {
            $lock->release();
        }
    }
}
