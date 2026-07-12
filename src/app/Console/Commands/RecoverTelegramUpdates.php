<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

class RecoverTelegramUpdates extends Command
{
    protected $signature = 'telegram:recover-updates {--chunk=100} {--queue=default}';

    protected $description = 'Redispatch stored Telegram updates that have not been processed or claimed yet.';

    public function handle(): int
    {
        $dispatched = 0;

        TelegramUpdate::query()
            ->whereNull('processed_at')
            ->whereNull('processing_started_at')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($updates) use (&$dispatched): void {
                foreach ($updates as $update) {
                    ProcessTelegramUpdate::dispatch($update->id, now()->toISOString())
                        ->onQueue((string) $this->option('queue'));
                    $dispatched++;
                }
            });

        $this->info("telegram:recover-updates completed cleanly: dispatched={$dispatched}");

        return self::SUCCESS;
    }
}
