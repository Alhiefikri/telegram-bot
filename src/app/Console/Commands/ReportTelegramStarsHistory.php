<?php

namespace App\Console\Commands;

use App\Services\TelegramStars\TelegramStarsHistoryReport;
use Illuminate\Console\Command;
use Throwable;

final class ReportTelegramStarsHistory extends Command
{
    protected $signature = 'telegram:report-stars-history {--offset=0} {--pages=1}';

    protected $description = 'Read-only bounded Stars history comparison for payment review; never settles or refunds.';

    public function handle(TelegramStarsHistoryReport $history): int
    {
        $offset = filter_var($this->option('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX - 500]]);
        $pages = filter_var($this->option('pages'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => TelegramStarsHistoryReport::MAX_PAGES]]);
        if ($offset === false || $pages === false) {
            $this->error('Invalid offset or page count (1–5 pages).');

            return self::INVALID;
        }
        try {
            $report = $history->scan($offset, $pages);
        } catch (Throwable) {
            // Transport errors may contain a token-bearing URL.
            $this->error('Telegram Stars history is unavailable; no conclusions are safe.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['findings'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
