<?php

namespace App\Console\Commands;

use App\Services\UsdtBalance\UsdtAccountingAggregator;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Bounded, idempotent catch-up of the USDT accounting aggregates. Re-running
 * it (for example after a restore or a crashed worker) can only replay events
 * whose unique consumer claim does not exist yet, so totals never double.
 */
final class AggregateUsdtAccounting extends Command
{
    private const BATCH_SIZE = 500;

    protected $signature = 'usdt-balance:aggregate-accounting
        {--consumer=reporting-v1 : Aggregate consumer name.}
        {--limit=2000 : Maximum accounting events to process in this run (1-100000).}
        {--json : Print the sanitized JSON status.}';

    protected $description = 'Consume USDT accounting events into idempotent per-leg aggregates without changing money.';

    public function handle(UsdtAccountingAggregator $aggregator): int
    {
        $consumer = trim((string) ($this->option('consumer') ?? ''));
        $limitValue = trim((string) ($this->option('limit') ?? ''));

        if ($consumer === '' || preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $consumer) !== 1) {
            $this->error('The --consumer option must be a lowercase name of at most 64 characters.');

            return self::FAILURE;
        }

        if (preg_match('/^[0-9]+$/', $limitValue) !== 1 || (int) $limitValue < 1 || (int) $limitValue > 100000) {
            $this->error('The --limit option must be an integer between 1 and 100000.');

            return self::FAILURE;
        }

        $remaining = (int) $limitValue;
        $processed = 0;

        try {
            while ($remaining > 0) {
                $batch = $aggregator->catchUp($consumer, min(self::BATCH_SIZE, $remaining));
                $processed += $batch->processedEvents;
                $remaining -= min(self::BATCH_SIZE, $remaining);

                if ($batch->processedEvents === 0) {
                    break;
                }
            }

            $status = $aggregator->status($consumer);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'consumer' => $consumer,
                'processed_events' => $processed,
            ] + $status->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'SUMMARY consumer=%s processed=%d pending=%d claimed=%d aggregated=%d consistent=%s',
                $consumer,
                $processed,
                $status->pendingEvents,
                $status->claimedEvents,
                $status->aggregatedEvents,
                $status->isConsistent() ? 'true' : 'false',
            ));
        }

        if (! $status->isConsistent()) {
            $this->error('USDT accounting aggregates do not cover every claim; no money was changed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
