<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceOperationsAlertService;
use Illuminate\Console\Command;

/**
 * Runs one balance operations alert evaluation pass (#558) and prints the
 * episode transition counts. Idempotent: re-running reconciles existing
 * episodes and never materializes a second Telegram Outbound Effect for the
 * same episode + fingerprint.
 */
final class ReportBalanceOperationsAlerts extends Command
{
    protected $signature = 'ops:balance-alerts {--json}';

    protected $description = 'Evaluate durable balance operations alerts and report episode counts.';

    public function handle(BalanceOperationsAlertService $alerts): int
    {
        $evaluation = $alerts->evaluate();
        $counts = $evaluation->toArray();

        if ($this->option('json')) {
            $this->line(json_encode($counts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Evaluasi alert operasi saldo');
        foreach ($counts as $name => $value) {
            $this->line(sprintf('%s: %d', $name, $value));
        }

        return self::SUCCESS;
    }
}
