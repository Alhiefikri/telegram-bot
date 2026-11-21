<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramStarsUpdate;
use App\Services\TelegramStars\TelegramStarsSettlement;
use Illuminate\Console\Command;
use Throwable;

final class RecoverTelegramStarsPayments extends Command
{
    protected $signature = 'telegram:recover-stars-payments {--limit=25}';

    protected $description = 'Settle pending authenticated Stars payment inbox entries, independent of the queue.';

    public function handle(TelegramStarsSettlement $settlement): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }

        $ids = TelegramUpdate::where('stars_event', TelegramStarsUpdate::SUCCESS)->whereNull('processed_at')
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id');
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $settlement->process($id);
            } catch (Throwable) {
                // Throttle a broken row, without dropping the evidence or
                // starving later arrivals. The queue has its own retry budget.
                TelegramUpdate::whereKey($id)->whereNull('processed_at')->update(['next_retry_at' => now()->addMinutes(5)]);
                $failed++;
            }
        }

        $this->info("Stars inbox: examined={$ids->count()} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
