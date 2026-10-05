<?php

namespace App\Console\Commands;

use App\Services\MoneyReceipts\ExternalMoneyReceiptBackfillResult;
use App\Services\MoneyReceipts\ExternalMoneyReceiptBackfillService;
use Illuminate\Console\Command;
use Throwable;

final class BackfillExternalMoneyReceipts extends Command
{
    protected $signature = 'balance:backfill-money-receipts
        {--source=all : Backfill source name or "all"}
        {--chunk=100 : Legacy rows processed per batch (1-1000)}
        {--after= : Resume after an explicit recorded cursor (single source only)}
        {--limit=0 : Maximum rows processed per source in this run (0 = unlimited)}
        {--dry-run : Inspect legacy rows without writing receipts, reservations or checkpoints}';

    protected $description = 'Backfill settled and pending legacy Binance/BSC/QRIS money identities and compensated funding sources into the external money receipt registry.';

    public function handle(ExternalMoneyReceiptBackfillService $backfill): int
    {
        $source = trim((string) $this->option('source'));
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $after = trim((string) $this->option('after'));
        $dryRun = (bool) $this->option('dry-run');

        if ($chunk === false || $chunk < 1 || $chunk > ExternalMoneyReceiptBackfillService::MAX_CHUNK) {
            $this->error('Money receipt backfill failed: chunk must be between 1 and '.ExternalMoneyReceiptBackfillService::MAX_CHUNK.'.');

            return self::FAILURE;
        }
        if ($limit === false || $limit < 0) {
            $this->error('Money receipt backfill failed: limit must be a non-negative integer.');

            return self::FAILURE;
        }

        if ($source === 'all') {
            if ($after !== '') {
                $this->error('Money receipt backfill failed: --after requires an explicit --source because each source has its own cursor.');

                return self::FAILURE;
            }
            $sources = ExternalMoneyReceiptBackfillService::SOURCES;
        } else {
            if (! in_array($source, ExternalMoneyReceiptBackfillService::SOURCES, true)) {
                $this->error('Money receipt backfill failed: unknown source ['.$source.'].');

                return self::FAILURE;
            }
            $sources = [$source];
        }

        $exitCode = self::SUCCESS;

        foreach ($sources as $current) {
            try {
                $result = $backfill->run(
                    $current,
                    $chunk,
                    $after === '' ? null : $after,
                    $limit,
                    $dryRun,
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Money receipt backfill failed for source '.$current.'.');

                return self::FAILURE;
            }

            $this->line($this->summaryLine($result));

            if (! $result->dryRun && $result->complete && ! $result->ready) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    private function summaryLine(ExternalMoneyReceiptBackfillResult $result): string
    {
        return sprintf(
            'SUMMARY source=%s mode=%s scanned=%d claimed=%d replayed=%d reserved=%d conflicts=%d reviews=%d skipped=%d cursor=%s complete=%s ready=%s',
            $result->source,
            $result->dryRun ? 'dry_run' : 'commit',
            $result->scanned,
            $result->claimed,
            $result->replayed,
            $result->reserved,
            $result->conflicts,
            $result->reviews,
            $result->skipped,
            $result->cursor ?? 'none',
            $result->complete ? 'true' : 'false',
            $result->ready ? 'true' : 'false',
        );
    }
}
