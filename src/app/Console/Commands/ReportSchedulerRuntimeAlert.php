<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceOperationsAlertService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ReportSchedulerRuntimeAlert extends Command
{
    protected $signature = 'ops:scheduler-runtime-alert {component} {state} {--reason=}';

    protected $description = 'Open or resolve a durable scheduler runtime alert episode.';

    public function handle(BalanceOperationsAlertService $alerts): int
    {
        $component = (string) $this->argument('component');
        $state = (string) $this->argument('state');
        $reason = $this->option('reason');

        if (! in_array($state, ['failed', 'recovered'], true)
            || ($state === 'failed' && ! is_string($reason))
            || ($state === 'recovered' && $reason !== null)) {
            $this->error('Runtime alert state or reason is invalid.');

            return self::FAILURE;
        }

        try {
            $alerts->reconcileSchedulerRuntime(
                $component,
                healthy: $state === 'recovered',
                reason: is_string($reason) ? $reason : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Runtime alert reconciled: component={$component} state={$state}.");

        return self::SUCCESS;
    }
}
