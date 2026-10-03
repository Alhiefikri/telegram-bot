<?php

namespace App\Console\Commands;

use App\Services\Warranty\WarrantyDeadlineReminderService;
use Illuminate\Console\Command;

final class NotifyWarrantyDeadlines extends Command
{
    protected $signature = 'suppliers:notify-warranty-deadlines {--limit=25 : Maximum units and effects per work type (1-100)}';

    protected $description = 'Materialize and dispatch buyer reminders before supplier claim deadlines without provider I/O';

    public function handle(WarrantyDeadlineReminderService $reminders): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Use a limit between 1 and 100.');

            return self::INVALID;
        }
        $result = $reminders->run($limit);
        $this->info('Warranty deadline reminders: '.$result['materialized'].' materialized, '.$result['dispatched'].' dispatched.');

        return self::SUCCESS;
    }
}
