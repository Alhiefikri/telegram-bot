<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramStarsUpdate;
use App\Services\TelegramStars\TelegramStarsRefunds;
use App\Services\TelegramStars\TelegramStarsSettlement;
use Illuminate\Console\Command;
use Throwable;

final class RecoverTelegramStarsPayments extends Command
{
    protected $signature = 'telegram:recover-stars-payments {--limit=25}';

    protected $description = 'Process pending authenticated Stars payment and refund evidence, independent of the queue.';

    public function handle(TelegramStarsSettlement $settlement, TelegramStarsRefunds $refunds): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }

        $updates = TelegramUpdate::whereIn('stars_event', [TelegramStarsUpdate::SUCCESS, TelegramStarsUpdate::REFUND])->whereNull('processed_at')
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get(['id', 'stars_event']);
        $failed = 0;
        foreach ($updates as $update) {
            try {
                if ($update->stars_event === TelegramStarsUpdate::REFUND) {
                    $refunds->process($update->id);
                } else {
                    $settlement->process($update->id);
                }
            } catch (Throwable) {
                // Throttle a broken row, without dropping the evidence or
                // starving later arrivals. The queue has its own retry budget.
                TelegramUpdate::whereKey($update->id)->whereNull('processed_at')->update(['next_retry_at' => now()->addMinutes(5)]);
                $failed++;
            }
        }

        $this->info("Stars inbox: examined={$updates->count()} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
