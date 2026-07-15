<?php

namespace App\Console\Commands;

use App\Models\CustomerLoginLink;
use Illuminate\Console\Command;

class CleanupCustomerLoginLinks extends Command
{
    protected $signature = 'customers:cleanup-login-links {--chunk=500}';

    protected $description = 'Remove expired and consumed customer login links in bounded batches';

    public function handle(): int
    {
        $deleted = 0;
        $chunk = max(1, (int) $this->option('chunk'));
        do {
            $ids = CustomerLoginLink::query()->where(function ($query): void {
                $query->whereNotNull('used_at')->orWhere('expires_at', '<', now());
            })->orderBy('id')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += CustomerLoginLink::query()->whereKey($ids)->delete();
        } while (true);

        $this->info("Deleted {$deleted} customer login links.");

        return self::SUCCESS;
    }
}
