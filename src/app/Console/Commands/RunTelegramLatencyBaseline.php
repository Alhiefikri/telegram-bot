<?php

namespace App\Console\Commands;

use App\Services\Telegram\LatencyBaseline\LatencyBaselineReport;
use App\Services\Telegram\LatencyBaseline\TelegramLatencyBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class RunTelegramLatencyBaseline extends Command
{
    protected $signature = 'telegram:latency-baseline
        {--scenario=idle : idle, backlog-expired, slow-notification, slow-payment, slow-provider, slow-telegram, or all}
        {--batches=1 : Measured batches per scenario (1-10)}
        {--samples=3 : Orders per batch (1-25)}
        {--warmup-batches=1 : Batches reported separately as cold start (0-3)}
        {--interval-ms=500 : Delay between orders in a batch (0-10000)}
        {--timeout-seconds=60 : Per-batch boundary timeout (5-300)}
        {--worker-sleep=3 : queue:work sleep seconds (0-5)}
        {--worker-topology=split : split workers per queue, or combined to reproduce the old shared worker}
        {--provider-latency-ms=0 : Local provider status delay for all scenarios (0-5000)}
        {--telegram-latency-ms=0 : Local fake Telegram delay for all scenarios (0-5000)}
        {--backlog=10 : Provider history rows for backlog-expired (0-50); slow-notification always uses one unrelated row}
        {--output= : Artifact directory}
        {--json : Print the report as JSON}
        {--confirm-disposable : Required acknowledgement that PostgreSQL/Redis are disposable}';

    protected $description = 'Run the bounded local paid-receipt latency baseline against disposable PostgreSQL/Redis.';

    public function handle(TelegramLatencyBaseline $baseline, LatencyBaselineReport $report): int
    {
        try {
            $options = $this->validatedOptions();

            if (! (bool) $this->option('confirm-disposable')) {
                throw new RuntimeException('Refusing to run without --confirm-disposable; the runner clears the Redis queue and cache.');
            }

            $runId = now()->utc()->format('Ymd\THis\Z').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
            $output = (string) ($this->option('output') ?: storage_path('app/telegram-latency-baseline/'.$runId));
            File::ensureDirectoryExists($output);

            $result = $baseline->run($options + ['run_id' => $runId, 'output' => $output]);
            $this->writeArtifacts($output, $runId, $result, $report);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->renderSummary($result);
                $this->line('Artifacts: '.$output);
            }

            foreach ($result['scenarios'] as $scenario => $scenarioResult) {
                if ((int) ($scenarioResult['warm']['counts']['incomplete'] ?? 0) > 0) {
                    $this->warn('Incomplete warm samples in '.$scenario.'; the run captured missing boundaries instead of zero-filling them.');

                    return self::FAILURE;
                }

                if (($scenarioResult['interaction_probe']['complete'] ?? true) === false) {
                    $this->warn('The payment/update isolation probe did not complete in '.$scenario.'; pickup and processing are reported as missing.');

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error('telegram:latency-baseline failed: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('telegram:latency-baseline failed: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function validatedOptions(): array
    {
        $scenarios = $this->scenarios();
        $batches = $this->intOption('batches', 1, 10);
        $samples = $this->intOption('samples', 1, 25);
        $warmup = $this->intOption('warmup-batches', 0, 3);
        $interval = $this->intOption('interval-ms', 0, 10_000);
        $timeout = $this->intOption('timeout-seconds', 5, 300);
        $workerSleep = $this->intOption('worker-sleep', 0, 5);
        $providerLatency = $this->intOption('provider-latency-ms', 0, 5_000);
        $telegramLatency = $this->intOption('telegram-latency-ms', 0, 5_000);
        $backlog = $this->intOption('backlog', 0, 50);
        $workerTopology = trim((string) $this->option('worker-topology'));

        if (! in_array($workerTopology, TelegramLatencyBaseline::WORKER_TOPOLOGIES, true)) {
            throw new RuntimeException('--worker-topology must be split or combined.');
        }

        return [
            'scenarios' => $scenarios,
            'batches' => $batches,
            'samples' => $samples,
            'warmup_batches' => $warmup,
            'interval_ms' => $interval,
            'timeout_seconds' => $timeout,
            'worker_sleep' => $workerSleep,
            'worker_topology' => $workerTopology,
            'provider_latency_ms' => $providerLatency,
            'telegram_latency_ms' => $telegramLatency,
            'backlog' => $backlog,
        ];
    }

    /** @return list<string> */
    private function scenarios(): array
    {
        $requested = array_values(array_unique(array_filter(array_map(
            static fn (string $scenario): string => trim($scenario),
            explode(',', (string) $this->option('scenario')),
        ))));

        if ($requested === []) {
            throw new RuntimeException('At least one scenario is required.');
        }

        if (in_array('all', $requested, true)) {
            return TelegramLatencyBaseline::SCENARIOS;
        }

        foreach ($requested as $scenario) {
            if (! in_array($scenario, TelegramLatencyBaseline::SCENARIOS, true)) {
                throw new RuntimeException('Unknown scenario: '.$scenario);
            }
        }

        return $requested;
    }

    private function intOption(string $name, int $minimum, int $maximum): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(sprintf('--%s must be between %d and %d.', $name, $minimum, $maximum));
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $result */
    private function writeArtifacts(string $output, string $runId, array $result, LatencyBaselineReport $report): void
    {
        $raw = [
            'run_id' => $runId,
            'generated_at' => $result['generated_at'],
            'contract' => $result['contract'],
            'environment' => $result['environment'],
            'scenarios' => array_map(static fn (array $scenario): array => [
                'dataset' => $scenario['dataset'],
                'worker' => $scenario['worker'],
                'workers' => $scenario['workers'] ?? [],
                'interaction_probe' => $scenario['interaction_probe'] ?? null,
                'fixture' => $scenario['fixture'],
                'samples' => $scenario['samples'],
            ], $result['scenarios']),
        ];

        File::put($output.'/raw-samples.json', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($output.'/report.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($output.'/report.md', $report->markdown($result));
    }

    /** @param array<string, mixed> $result */
    private function renderSummary(array $result): void
    {
        foreach ($result['scenarios'] as $scenario => $scenarioResult) {
            $warm = $scenarioResult['warm']['boundaries'];
            $this->line('');
            $this->line('Scenario: '.$scenario.' (warm samples: '.$scenarioResult['warm']['sample_count'].', cold: '.$scenarioResult['cold']['sample_count'].')');
            if (is_array($scenarioResult['interaction_probe'] ?? null)) {
                $probe = $scenarioResult['interaction_probe'];
                $this->line(sprintf(
                    'Isolation probe: topology=%s update enqueue->pickup=%s ms pickup->processed=%s ms picked_up=%s processed=%s',
                    (string) ($probe['worker_topology'] ?? 'unknown'),
                    $probe['queue_wait_ms'] ?? 'unknown',
                    $probe['pickup_to_processed_ms'] ?? 'unknown',
                    ($probe['picked_up'] ?? false) ? 'yes' : 'no',
                    ($probe['processed'] ?? false) ? 'yes' : 'no',
                ));
            }

            $this->table(
                ['Boundary', 'n', 'p50 ms', 'p95 ms', 'max ms', 'unknown'],
                array_map(static fn (string $boundary, array $values): array => [
                    $boundary,
                    (int) $values['samples'],
                    $values['p50_ms'] ?? 'unknown',
                    $values['p95_ms'] ?? 'unknown',
                    $values['max_ms'] ?? 'unknown',
                    (int) $values['unknown'],
                ], array_keys($warm), array_values($warm)),
            );
        }
    }
}
