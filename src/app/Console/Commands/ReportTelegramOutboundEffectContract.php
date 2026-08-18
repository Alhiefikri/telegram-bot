<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramOutboundEffectContractReport;
use Illuminate\Console\Command;

class ReportTelegramOutboundEffectContract extends Command
{
    protected $signature = 'telegram:outbound-contract {--json : Emit only the machine-readable report} {--strict : Exit unsuccessfully unless the final contract is applied and clean}';

    protected $description = 'Report whether Telegram outbound effects are ready for the ledger-only contract.';

    public function handle(TelegramOutboundEffectContractReport $report): int
    {
        $summary = $report->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('telegram outbound contract: '.($summary['contract_ready'] ? 'ready' : 'blocked'));
            $this->line('migration safe: '.($summary['preflight_ready'] ? 'yes' : 'no'));
            $this->line('legacy columns: '.array_sum(array_map('count', $summary['legacy_columns_present'])));
            $this->line('legacy indexes: '.count($summary['legacy_indexes_present']));
            $this->line('unresolved evidence: '.(int) $summary['unresolved_total']);
            $this->line('source references: '.count($summary['source_code_references']));
        }

        return (bool) $this->option('strict') && ! $summary['contract_ready']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
