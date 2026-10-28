<?php

namespace App\Console\Commands;

use App\Services\Telegram\Retention\NotificationRetentionDryRun;
use App\Services\Telegram\Retention\RetentionWindow;
use Illuminate\Console\Command;
use Throwable;

final class DryRunNotificationRetention extends Command
{
    protected $signature = 'telegram:notification-retention-dry-run
        {--family= : Allowlisted root family}
        {--policy-version= : Explicit classification policy version}
        {--as-of= : Fixed ISO8601 instant with numeric timezone offset}
        {--timezone= : Application IANA timezone}
        {--horizon= : Sensitivity scenario: 30, 90 or 180 days, not a retention policy}
        {--after= : Restricted operator starting primary key}
        {--stop= : Restricted operator inclusive high-watermark}
        {--resume= : Restricted operator resume manifest from a previous run}
        {--page=100 : Roots per short transaction, maximum 100}
        {--roots=1000 : Maximum roots per run}
        {--dependencies=1000 : Maximum dependency rows including truncation probes}
        {--per-root=200 : Maximum dependency rows per root including truncation probes}
        {--seconds=10 : Maximum scan wall-clock seconds}
        {--details=20 : Maximum redacted details}
        {--operator-artifact : Include raw cursor/resume data; restrict access to this output}
        {--json : Emit JSON only}';

    protected $description = 'Classify notification retention evidence read-only; never archive, delete, repair or resend.';

    public function handle(NotificationRetentionDryRun $scan): int
    {
        try {
            $input = $this->options();
            $input['per_root'] = $input['per-root'];
            $window = new RetentionWindow($input);
        } catch (Throwable) {
            $this->line('{"schema":"notification-retention-report-v1","error_code":"invalid_input","delete_candidates":0}');

            return self::FAILURE;
        }
        [$report, $exit] = $scan->run($window);
        if (! $this->option('operator-artifact')) {
            unset($report['operator_artifact']);
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Notification retention dry-run: read-only; no mutation authority.');
            // One contract for both renderers, including all budgets and limitations.
            foreach ($report as $key => $value) {
                $this->line($key.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        }

        return $exit;
    }
}
