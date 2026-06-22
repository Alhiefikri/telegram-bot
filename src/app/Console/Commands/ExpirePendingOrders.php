<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderStateMachine;
use Illuminate\Console\Command;
use Throwable;

class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending {--chunk=100}';

    protected $description = 'Expire due pending-payment orders and release reservations.';

    public function handle(OrderStateMachine $orders): int
    {
        Order::where('order_status', OrderStatus::PendingPayment)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById((int) $this->option('chunk'), function ($chunk) use ($orders) {
                foreach ($chunk as $order) {
                    try {
                        $orders->expirePendingOrder($order);
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("Failed expiring order {$order->id}: {$e->getMessage()}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
