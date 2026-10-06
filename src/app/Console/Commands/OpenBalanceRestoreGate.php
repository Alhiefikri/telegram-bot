<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceRestoreGateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Opens the post-restore gate (#558). Until the operator records an external
 * evidence replay and a clean full reconciliation run, new USDT funding and
 * spending stay blocked; reconciliation, settlement, refund and reversal of
 * money already in the system keep running.
 */
final class OpenBalanceRestoreGate extends Command
{
    protected $signature = 'usdt-balance:restore-open
        {--window-start= : ISO8601 instant the restored backup was taken}
        {--window-end= : ISO8601 instant the restore completed and writes stopped}
        {--reason= : Operator reason for the restore}
        {--actor= : Operator identity recorded on the gate evidence}
        {--json : Print the sanitized JSON status}';

    protected $description = 'Block new USDT funding/spending after a database restore until external evidence is replayed.';

    public function handle(BalanceRestoreGateService $gate): int
    {
        try {
            $start = CarbonImmutable::parse(trim((string) $this->option('window-start')));
            $end = CarbonImmutable::parse(trim((string) $this->option('window-end')));
            $opened = $gate->open(
                $start,
                $end,
                (string) ($this->option('reason') ?? ''),
                (string) ($this->option('actor') ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'state' => $opened->state->value,
            'window_start' => $opened->window_start?->toIso8601String(),
            'window_end' => $opened->window_end?->toIso8601String(),
            'opened_at' => $opened->opened_at?->toIso8601String(),
            'opened_by' => $opened->opened_by,
            'next_step' => 'Run usdt-balance:restore-replay, then usdt-balance:reconcile, then usdt-balance:restore-close.',
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Balance restore gate opened; new USDT funding and spending are blocked.');
            $this->line(sprintf('  window: %s .. %s', $payload['window_start'], $payload['window_end']));
            $this->line(sprintf('  opened_by: %s', $payload['opened_by']));
            $this->line('  next: '.$payload['next_step']);
        }

        return self::SUCCESS;
    }
}
