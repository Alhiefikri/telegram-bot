<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\UsdtBalance\UsdtBalanceDriftFreezer;
use App\Services\UsdtBalance\UsdtBalanceFreezeOutcome;
use App\Services\UsdtReconciliation\UsdtReconciliation;
use App\Services\UsdtReconciliation\UsdtReconciliationResult;
use App\Services\UsdtReconciliation\UsdtReconciliationRuns;
use App\Services\UsdtReconciliation\UsdtReconciliationScope;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded, read-only USDT reconciliation: projection/cursor/history drift,
 * receipts versus settled ledger entries, liability/unresolved deposits
 * versus qualified custody assets and audited treasury movements, plus the
 * accounting aggregate state. Drifted accounts are frozen (scoped, explicit,
 * no money written) unless --no-freeze is passed; every run is recorded as
 * sanitized, append-only evidence. Non-zero exit means findings, never a
 * repaired balance.
 */
final class ReconcileUsdtBalance extends Command
{
    protected $signature = 'usdt-balance:reconcile
        {--customer= : Inspect only this Customer id.}
        {--cursor= : Resume after this account id.}
        {--receipt-cursor= : Resume receipts after this receipt id.}
        {--credit-cursor= : Resume unreceipted funding credits after this entry id.}
        {--complete : Inspect every page of every facet in one snapshot; no Customer or resume cursors.}
        {--limit=200 : Rows per page per facet (1-1000).}
        {--no-freeze : Report drift without freezing the scoped accounts.}
        {--actor= : Operator identity to record on the run evidence.}
        {--json : Print the sanitized JSON report.}';

    protected $description = 'Reconcile the Customer USDT subledger, receipts and treasury coverage without changing money.';

    public function handle(
        UsdtReconciliation $reconciliation,
        UsdtBalanceDriftFreezer $freezer,
        UsdtReconciliationRuns $runs,
    ): int {
        try {
            $scope = $this->scope();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($scope->customerId !== null && ! Customer::query()->whereKey($scope->customerId)->exists()) {
            $this->error('Customer not found.');

            return self::FAILURE;
        }

        $result = $reconciliation->run($scope);

        $freezeOutcomes = [];
        if (! (bool) $this->option('no-freeze') && $result->accounts->findings !== []) {
            foreach ($result->accounts->findings as $finding) {
                try {
                    $freezeOutcomes[$finding->accountId] = $freezer->freeze($finding);
                } catch (Throwable $exception) {
                    // One failed freeze must not lose the other accounts or the
                    // run evidence; the scoped account stays visible as failed.
                    $freezeOutcomes[$finding->accountId] = UsdtBalanceFreezeOutcome::Failed;
                    $this->warn(sprintf(
                        'FREEZE_FAILED account_id=%d class=%s',
                        $finding->accountId,
                        $exception::class,
                    ));
                }
            }
        }

        $actor = (string) ($this->option('actor') ?? '');
        $run = $runs->record($result, $freezeOutcomes, $actor === '' ? null : $actor);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray($freezeOutcomes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($result, $freezeOutcomes);
        }

        if ($result->hasFindings()) {
            $this->error(sprintf(
                'USDT reconciliation recorded findings in run %d; no money was changed.',
                $run->id,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function scope(): UsdtReconciliationScope
    {
        $customer = $this->positiveIntOption('customer', null);
        $accountCursor = $this->positiveIntOption('cursor', null);
        $receiptCursor = $this->positiveIntOption('receipt-cursor', null);
        $creditCursor = $this->positiveIntOption('credit-cursor', null);
        $limit = $this->positiveIntOption('limit', 200);

        return new UsdtReconciliationScope(
            limit: $limit,
            customerId: $customer,
            afterAccountId: $accountCursor,
            afterReceiptId: $receiptCursor,
            afterCreditId: $creditCursor,
            complete: (bool) $this->option('complete'),
        );
    }

    private function positiveIntOption(string $name, ?int $default): ?int
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            return $default;
        }

        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new InvalidArgumentException("The --{$name} option must be a non-negative integer.");
        }

        return (int) $value;
    }

    /**
     * @param  array<int, UsdtBalanceFreezeOutcome>  $freezeOutcomes
     */
    private function renderText(UsdtReconciliationResult $result, array $freezeOutcomes): void
    {
        foreach ($result->accounts->findings as $finding) {
            $this->warn(sprintf(
                'ACCOUNT_DRIFT account_id=%d customer_id=%d projected=%d ledger=%d version=%d entries=%d issues=%s freeze=%s',
                $finding->accountId,
                $finding->customerId,
                $finding->projectedMicro,
                $finding->ledgerMicro,
                $finding->version,
                $finding->entryCount,
                implode(',', array_map(static fn ($issue): string => $issue->value, $finding->issues)),
                isset($freezeOutcomes[$finding->accountId]) ? $freezeOutcomes[$finding->accountId]->value : 'skipped',
            ));
        }

        foreach ($result->receipts->findings as $finding) {
            $this->warn(sprintf(
                'RECEIPT_FINDING kind=%s receipt_id=%s entry_id=%s customer_id=%s provider=%s currency=%s target=%s receipt_micro=%s credited_micro=%s difference_micro=%s',
                $finding->kind->value,
                $finding->receiptId ?? 'none',
                $finding->entryId ?? 'none',
                $finding->customerId ?? 'none',
                $finding->provider ?? 'none',
                $finding->currency,
                $finding->targetId,
                $finding->receiptMicro ?? 'unrepresentable',
                $finding->creditedMicro ?? 'none',
                $finding->differenceMicro ?? 'none',
            ));
        }

        $treasury = $result->treasury;
        $this->line(sprintf(
            'TREASURY verdict=%s liability=%s projected=%s unresolved=%s overcredited=%s custody_state=%s custody_assets=%s movements_in=%s movements_out=%s movements=%d',
            $treasury->verdict->value,
            $treasury->ledgerLiabilityMicro->toString(),
            $treasury->projectedLiabilityMicro->toString(),
            $treasury->unresolvedDepositMicro->toString(),
            $treasury->overcreditedMicro->toString(),
            $treasury->custodyState ?? 'missing',
            $treasury->custodyAssetsMicro?->toString() ?? 'unavailable',
            $treasury->movementInMicro->toString(),
            $treasury->movementOutMicro->toString(),
            $treasury->movementCount,
        ));

        if ($treasury->reorgIncidentCount > 0) {
            $this->warn(sprintf(
                'REORG_LOSS incidents=%d exposure_micro=%s spent_exposure_micro=%s authorized_recovery_cases=%d status=unresolved',
                $treasury->reorgIncidentCount,
                $treasury->reorgExposureMicro?->toString() ?? '0',
                $treasury->reorgSpentExposureMicro?->toString() ?? '0',
                $treasury->authorizedRecoveryCaseCount,
            ));
        }

        $accounting = $result->accounting;
        $this->line(sprintf(
            'ACCOUNTING consumer=%s pending=%d claimed=%d aggregated=%d consistent=%s',
            $accounting->consumer,
            $accounting->pendingEvents,
            $accounting->claimedEvents,
            $accounting->aggregatedEvents,
            $accounting->isConsistent() ? 'true' : 'false',
        ));

        $this->line(sprintf(
            'SUMMARY verdict=%s accounts_inspected=%d account_findings=%d frozen=%d receipt_findings=%d treasury_findings=%d accounting_consistent=%s',
            $result->verdict()->value,
            $result->accounts->accountsInspected,
            count($result->accounts->findings),
            count($freezeOutcomes),
            count($result->receipts->findings),
            count($treasury->findings),
            $accounting->isConsistent() ? 'true' : 'false',
        ));
    }
}
