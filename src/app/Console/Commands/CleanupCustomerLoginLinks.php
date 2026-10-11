<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupCustomerLoginLinks extends Command
{
    protected $signature = 'customers:cleanup-login-links {--chunk=500}';

    protected $description = 'Remove one bounded page of eligible legacy login links without deleting retained mail work.';

    public function handle(): int
    {
        return $this->call('customer-auth:cleanup', [
            '--limit' => $this->option('chunk'),
            '--legacy-only' => true,
        ]);
    }
}
