<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $orderId) {}

    public function handle(TelegramReceiptNotifier $notifier): void
    {
        $notifier->send(Order::findOrFail($this->orderId));
    }
}
