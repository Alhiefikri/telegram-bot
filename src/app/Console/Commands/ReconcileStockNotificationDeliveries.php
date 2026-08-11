<?php

namespace App\Console\Commands;

use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Console\Command;

class ReconcileStockNotificationDeliveries extends Command
{
    protected $signature = 'telegram:reconcile-stock-notifications {--chunk=100}';

    protected $description = 'Requeue stock notification recipients with expired claims or retryable failures.';

    public function handle(StockNotificationDeliveryPipeline $pipeline): int
    {
        $count = $pipeline->reconcile((int) $this->option('chunk'));
        $this->info("Queued {$count} stock notification event(s).");

        return self::SUCCESS;
    }
}
