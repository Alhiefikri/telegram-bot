<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramReceiptNotifier
{
    public function __construct(private TelegramBotClient $bot) {}

    public function send(Order $order): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $lock = Cache::lock('telegram-receipt:'.$order->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $claimed = DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->payment_status !== PaymentStatus::Paid || $locked->telegram_receipt_sent_at !== null) {
                    return null;
                }

                return $locked;
            });

            if (! $claimed) {
                return;
            }

            $claimed->loadMissing('customer');
            if (! $claimed->customer?->telegram_id) {
                return;
            }

            $this->bot->sendMessage($claimed->customer->telegram_id, 'Pembayaran diterima untuk '.$claimed->invoice_number.'. Terima kasih.');
            Order::whereKey($claimed->id)->whereNull('telegram_receipt_sent_at')->update(['telegram_receipt_sent_at' => now()]);
        } finally {
            $lock->release();
        }
    }
}
