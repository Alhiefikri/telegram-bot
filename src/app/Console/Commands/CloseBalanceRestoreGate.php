<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceRestoreGateService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Clears the post-restore gate (#558). Refuses while the external evidence
 * replay is missing or failed, or while the latest full reconciliation run
 * recorded after that replay still reports any drift.
 */
final class CloseBalanceRestoreGate extends Command
{
    protected $signature = 'usdt-balance:restore-close
        {--actor=operator : Operator identity recorded on the closing evidence}
        {--json : Print the sanitized JSON status}';

    protected $description = 'Reopen new USDT funding/spending only after a clean post-restore replay and reconciliation.';

    public function handle(BalanceRestoreGateService $gate): int
    {
        try {
            $closed = $gate->close((string) ($this->option('actor') ?? ''));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'state' => $closed->state->value,
            'cleared_at' => $closed->cleared_at?->toIso8601String(),
            'cleared_by' => $closed->cleared_by,
            'evidence' => $closed->cleared_evidence,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Balance restore gate cleared; new USDT funding and spending are allowed again.');
            $this->line(sprintf('  cleared_by: %s at %s', $payload['cleared_by'], $payload['cleared_at']));
        }

        return self::SUCCESS;
    }
}
