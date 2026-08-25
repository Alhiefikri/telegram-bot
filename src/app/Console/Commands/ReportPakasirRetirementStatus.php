<?php

namespace App\Console\Commands;

use App\Services\Pakasir\PakasirRetirementGate;
use Illuminate\Console\Command;

class ReportPakasirRetirementStatus extends Command
{
    protected $signature = 'pakasir:retirement-status {--json : Emit a redacted JSON report}';

    protected $description = 'Report whether the legacy Pakasir settlement lane is ready for owner approval before final removal.';

    public function handle(PakasirRetirementGate $gate): int
    {
        $report = $gate->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Pakasir retirement settlement: '.$report['gate']);
            $this->line('Non-terminal Payments: '.$report['non_terminal_payments']);
            $this->line('Dispatchable cancellation work: '.$report['dispatchable_cancellation_work']);
            if ($report['owner_approval_required']) {
                $this->warn('Owner approval must be recorded before deleting the compatibility lane.');
            }
        }

        return $report['settlement_ready'] ? self::SUCCESS : self::FAILURE;
    }
}
