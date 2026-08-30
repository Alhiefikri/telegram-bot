<?php

namespace App\Console\Commands;

use App\Services\ProductWaitlistNotificationService;
use Illuminate\Console\Command;

class ReconcileProductWaitlist extends Command
{
    protected $signature = 'waitlist:reconcile {--chunk=100}';

    protected $description = 'Requeue product waitlist materialization and retryable deliveries.';

    public function handle(ProductWaitlistNotificationService $notifications): int
    {
        $count = $notifications->reconcile((int) $this->option('chunk'));
        $this->info("Queued {$count} waitlist work item(s).");

        return self::SUCCESS;
    }
}
