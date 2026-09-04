<?php

namespace App\Console\Commands;

use App\Enums\SakurupiahEnvironment;
use App\Services\Sakurupiah\SakurupiahOperationalMetrics;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Illuminate\Console\Command;
use Throwable;

final class CheckSakurupiahReadiness extends Command
{
    protected $signature = 'sakurupiah:check-readiness
        {--json : Print a sanitized JSON readiness report}';

    protected $description = 'Check Sakurupiah environment readiness and the bounded local reconciliation backlog.';

    public function handle(SakurupiahProviderReadiness $readiness, SakurupiahOperationalMetrics $metrics): int
    {
        try {
            $environment = SakurupiahEnvironment::runtime();
            $report = $readiness->report($environment);
            $backlog = $metrics->pendingVerification();
            $payload = [
                'environment' => $environment->value,
                'enabled' => $report->enabled(),
                'ready' => $report->isReady(),
                'scheduling_enabled' => $readiness->schedulingEnabledFor($environment),
                'missing' => $report->missing(),
                'invalid' => array_keys($report->invalid()),
                'pending' => $backlog['pending'],
                'verification_pending' => $backlog['verification_pending'],
                'terminal_recent' => $backlog['terminal_recent'],
                'oldest_pending_age_seconds' => $backlog['oldest_age_seconds'],
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
            } else {
                $this->line(sprintf(
                    'SUMMARY environment=%s enabled=%s ready=%s scheduling=%s pending=%d verification_pending=%d terminal_recent=%d oldest_pending_age_seconds=%d',
                    $payload['environment'],
                    $payload['enabled'] ? 'true' : 'false',
                    $payload['ready'] ? 'true' : 'false',
                    $payload['scheduling_enabled'] ? 'true' : 'false',
                    $payload['pending'],
                    $payload['verification_pending'],
                    $payload['terminal_recent'],
                    $payload['oldest_pending_age_seconds'],
                ));
                if (! $report->isReady() && $report->enabled()) {
                    $this->line($report->configurationMessage());
                }
            }

            // Disabled is a safe deployment state. An enabled provider that is
            // not config-ready is a failure everywhere; an enabled production
            // provider whose reconciliation is not scheduled is also a release
            // failure (the scheduler is the only automatic recovery path).
            $mustBeScheduled = $environment === SakurupiahEnvironment::Production;
            $gatePasses = ! $report->enabled()
                || ($report->isReady()
                    && (! $mustBeScheduled || $payload['scheduling_enabled']));

            return $gatePasses ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Sakurupiah readiness check failed safely.');

            return self::FAILURE;
        }
    }
}
