<?php

namespace App\Console\Commands;

use App\Services\Bsc\BscCapabilityProbe;
use Illuminate\Console\Command;
use Throwable;

final class CheckBscCapabilities extends Command
{
    protected $signature = 'bsc:check-capabilities
        {--json : Print a sanitized JSON capability report}';

    protected $description = 'Probe BSC RPC capabilities (chain, token metadata, finality, canonical receipts, transfer logs) and fail closed on mismatch or unknown evidence.';

    public function handle(BscCapabilityProbe $probe): int
    {
        try {
            $report = $probe->probe();
        } catch (Throwable) {
            $this->error('BSC capability probe failed safely.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR));
        } else {
            $rows = [];
            foreach ($report->toArray()['capabilities'] as $capability => $entry) {
                $rows[] = [$capability, $entry['status'], $entry['detail']];
            }

            $this->table(['capability', 'status', 'detail'], $rows);
            $this->line(sprintf('SUMMARY rpc_qualified=%s', $report->isQualified() ? 'true' : 'false'));
        }

        return $report->isQualified() ? self::SUCCESS : self::FAILURE;
    }
}
