<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

class RecoverTelegramUpdates extends Command
{
    protected $signature = 'telegram:recover-updates {--chunk=100} {--queue=default}';

    protected $description = 'Redispatch stored Telegram updates that are unclaimed or whose processing lease expired.';

    public function handle(): int
    {
        $dispatched = 0;

        TelegramUpdate::query()
            ->whereNull('processed_at')
            ->whereNull('terminal_failed_at')
            ->where(fn ($query) => $query
                ->whereNull('next_retry_at')
                ->orWhere('next_retry_at', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('processing_started_at')
                ->orWhere('processing_started_at', '<=', now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)))))
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($updates) use (&$dispatched): void {
                foreach ($updates as $update) {
                    $claimedForDispatch = TelegramUpdate::query()
                        ->whereKey($update->id)
                        ->whereNull('processed_at')
                        ->whereNull('terminal_failed_at')
                        ->where(fn ($query) => $query
                            ->whereNull('next_retry_at')
                            ->orWhere('next_retry_at', '<=', now()))
                        ->update(['next_retry_at' => now()->addSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)))]);

                    if ($claimedForDispatch !== 1) {
                        continue;
                    }

                    ProcessTelegramUpdate::dispatch($update->id, now()->toISOString())
                        ->onQueue((string) $this->option('queue'));
                    $dispatched++;
                }
            });

        $this->info("telegram:recover-updates completed cleanly: dispatched={$dispatched}");

        return self::SUCCESS;
    }
}
