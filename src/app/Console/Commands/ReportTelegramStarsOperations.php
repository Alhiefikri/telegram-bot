<?php

namespace App\Console\Commands;

use App\Services\TelegramStars\TelegramStarsOperations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReportTelegramStarsOperations extends Command
{
    protected $signature = 'telegram:report-stars-operations {--alert : Emit a sanitized warning for aged refund claims or inbox backlog}';

    protected $description = 'Report isolated XTR accounting and unresolved Stars work without external calls.';

    public function handle(TelegramStarsOperations $operations): int
    {
        $counts = $operations->counts();
        if ($this->option('alert') && ($counts['refunds_over_5_min'] > 0 || $counts['unprocessed_inbox'] > 0 || $counts['refund_state_drift'] > 0)) {
            Log::warning('telegram.stars_unresolved_work', $counts);
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
