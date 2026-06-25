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
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        Order::where('order_status', OrderStatus::PendingPayment)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById((int) $this->option('chunk'), function ($chunk) use ($orders, &$processed, &$skipped, &$failed): void {
                foreach ($chunk as $order) {
                    try {
                        if ($orders->expirePendingOrder($order) === null) {
                            $skipped++;

                            continue;
                        }

                        $processed++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $this->error("Failed expiring order {$order->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->writeSummary($processed, $skipped, $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function writeSummary(int $processed, int $skipped, int $failed): void
    {
        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";

        if ($failed > 0) {
            $this->error('orders:expire-pending completed with partial failures: '.$summary);

            return;
        }

        if ($skipped > 0) {
            $this->warn('orders:expire-pending completed with skips: '.$summary);

            return;
        }

        $this->info('orders:expire-pending completed cleanly: '.$summary);
    }
}
