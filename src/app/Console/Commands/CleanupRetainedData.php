<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PaymentEvent;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

class CleanupRetainedData extends Command
{
    protected $signature = 'ops:cleanup-retained-data {--chunk=500}';

    protected $description = 'Remove retained webhook, payment, and audit data in bounded batches';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subDays(max(1, (int) config('services.data_retention_days', 90)));

        $telegram = $this->deleteInChunks(TelegramUpdate::query()->whereNotNull('processed_at')->where('created_at', '<', $cutoff), $chunk);
        $payment = $this->deleteInChunks(PaymentEvent::query()->where('received_at', '<', $cutoff), $chunk);
        $audit = $this->deleteInChunks(AuditLog::query()->where('created_at', '<', $cutoff), $chunk);

        $this->info("Deleted retained data: telegram_updates={$telegram} payment_events={$payment} audit_logs={$audit}.");

        return self::SUCCESS;
    }

    private function deleteInChunks($query, int $chunk): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += (clone $query)->whereKey($ids)->delete();
        } while (true);

        return $deleted;
    }
}
