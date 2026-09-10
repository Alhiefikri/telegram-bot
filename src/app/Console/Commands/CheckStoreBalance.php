<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\StoreBalance\StoreBalanceReconciliation;
use Illuminate\Console\Command;

final class CheckStoreBalance extends Command
{
    protected $signature = 'store-balance:check
        {--customer= : Inspect only this Customer id.}
        {--limit=100 : Maximum number of Customer accounts to inspect.}
        {--json : Print a sanitized JSON report.}';

    protected $description = 'Detect Store Balance projection and immutable-ledger mismatches without changing money.';

    public function handle(StoreBalanceReconciliation $reconciliation): int
    {
        $customerOption = (string) ($this->option('customer') ?? '');
        $customer = null;
        if ($customerOption !== '') {
            if (preg_match('/^[1-9][0-9]*$/', $customerOption) !== 1) {
                $this->error('The --customer option must be a positive Customer id.');

                return self::FAILURE;
            }

            $customer = Customer::query()->find((int) $customerOption);
            if ($customer === null) {
                $this->error('Customer not found.');

                return self::FAILURE;
            }
        }

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('The --limit option must be at least 1.');

            return self::FAILURE;
        }

        $report = $reconciliation->inspect($customer, $limit);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'consistent' => $report->isConsistent(),
                'accounts_inspected' => $report->accountsInspected,
                'mismatch_count' => count($report->mismatches),
                'mismatches' => array_map(static fn ($mismatch): array => [
                    'customer_id' => $mismatch->customerId,
                    'projected_balance' => $mismatch->projectedBalance,
                    'ledger_balance' => $mismatch->ledgerBalance,
                ], $report->mismatches),
            ], JSON_THROW_ON_ERROR));

            return $report->isConsistent() ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report->mismatches as $mismatch) {
            $this->warn(sprintf(
                'MISMATCH customer_id=%d projected=%d ledger=%d',
                $mismatch->customerId,
                $mismatch->projectedBalance,
                $mismatch->ledgerBalance,
            ));
        }

        $this->line(sprintf(
            'SUMMARY consistent=%s accounts_inspected=%d mismatches=%d',
            $report->isConsistent() ? 'true' : 'false',
            $report->accountsInspected,
            count($report->mismatches),
        ));

        if (! $report->isConsistent()) {
            $this->error('Store Balance projection mismatches detected; no money was changed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
