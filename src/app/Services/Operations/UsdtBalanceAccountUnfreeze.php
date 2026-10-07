<?php

namespace App\Services\Operations;

use App\Enums\UsdtBalanceDriftIssue;
use App\Enums\UsdtBalanceUnfreezeStatus;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\UsdtBalance\UsdtBalanceDriftFinding;
use App\Services\UsdtBalance\UsdtBalanceReconciliation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deliberate operator unfreeze for a frozen Customer USDT Balance account
 * (#558).
 *
 * A freeze is written by reconciliation drift detection or by a reorg
 * receiver detection or incident. Only a recognized drift freeze may be
 * cleared, and only when a fresh re-inspection under the account row lock proves the
 * drift is gone. A reorg incident keeps the freeze: the loss is resolved by
 * the authorized U8 recovery process, never by clearing the reason. The
 * service never writes a ledger entry, projection balance or receipt.
 */
final class UsdtBalanceAccountUnfreeze
{
    public function __construct(
        private readonly UsdtBalanceReconciliation $reconciliation,
        private readonly BalanceOperationRetry $retry,
        private readonly AuditLogger $audit,
    ) {}

    public function unfreeze(
        Customer $customer,
        mixed $reason,
        mixed $actor,
        bool $dryRun = false,
    ): UsdtBalanceUnfreezeResult {
        $reason = $this->boundedText('reason', $reason, 191);
        $actor = $this->boundedText('actor', $actor, 191);

        return $this->retry->transaction(function () use ($customer, $reason, $actor, $dryRun): UsdtBalanceUnfreezeResult {
            $account = DB::table('customer_usdt_balance_accounts')
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->first(['id', 'customer_id', 'frozen_reason']);

            if ($account === null) {
                return $this->result(UsdtBalanceUnfreezeStatus::MissingAccount, $customer, null, null, [], $actor, $reason);
            }

            $accountId = (int) $account->id;
            $frozenReason = $account->frozen_reason === null ? null : (string) $account->frozen_reason;

            if ($frozenReason === null) {
                return $this->result(UsdtBalanceUnfreezeStatus::NotFrozen, $customer, $accountId, null, [], $actor, $reason);
            }

            // Scanner containment commits under this same account lock before
            // the monitor materializes incidents. Its evidence must also win
            // when an existing drift freeze hid the receiver reorg marker.
            if (str_starts_with($frozenReason, 'usdt_reorg_incident:')
                || str_starts_with($frozenReason, 'usdt_receiver_reorg:')
                || DB::table('usdt_reorg_incidents')->where('account_id', $accountId)->exists()
                || DB::table('usdt_reorg_receiver_evidence as evidence')
                    ->join('customer_usdt_deposit_receivers as receivers', 'receivers.id', '=', 'evidence.receiver_id')
                    ->where('receivers.customer_id', $account->customer_id)
                    ->exists()) {
                return $this->result(UsdtBalanceUnfreezeStatus::ReorgHold, $customer, $accountId, $frozenReason, [], $actor, $reason);
            }

            if (! str_starts_with($frozenReason, 'reconciliation_drift:')
                || UsdtBalanceDriftIssue::tryFrom(substr($frozenReason, strlen('reconciliation_drift:'))) === null) {
                return $this->result(UsdtBalanceUnfreezeStatus::UnsupportedFreeze, $customer, $accountId, $frozenReason, [], $actor, $reason);
            }

            $finding = $this->reconciliation->inspectAccount($accountId);
            if ($finding instanceof UsdtBalanceDriftFinding) {
                return $this->result(
                    UsdtBalanceUnfreezeStatus::DriftRemains,
                    $customer,
                    $accountId,
                    $frozenReason,
                    array_map(static fn ($issue): string => $issue->value, $finding->issues),
                    $actor,
                    $reason,
                );
            }

            if ($dryRun) {
                return $this->result(UsdtBalanceUnfreezeStatus::DryRunEligible, $customer, $accountId, $frozenReason, [], $actor, $reason);
            }

            DB::table('customer_usdt_balance_accounts')->where('id', $accountId)->update([
                'frozen_reason' => null,
                'updated_at' => now(),
            ]);

            $this->audit->log('usdt_balance.account_unfrozen', null, [
                'frozen_reason' => $frozenReason,
            ], [
                'customer_id' => (int) $customer->getKey(),
                'account_id' => $accountId,
                'reason' => $reason,
                'actor' => $actor,
            ]);

            return $this->result(UsdtBalanceUnfreezeStatus::Unfrozen, $customer, $accountId, $frozenReason, [], $actor, $reason);
        }, 3);
    }

    /**
     * @param  list<string>  $issues
     */
    private function result(
        UsdtBalanceUnfreezeStatus $status,
        Customer $customer,
        ?int $accountId,
        ?string $frozenReason,
        array $issues,
        string $actor,
        string $reason,
    ): UsdtBalanceUnfreezeResult {
        return new UsdtBalanceUnfreezeResult(
            status: $status,
            customerId: (int) $customer->getKey(),
            accountId: $accountId,
            frozenReason: $frozenReason,
            issues: $issues,
            actor: $actor,
            reason: $reason,
        );
    }

    private function boundedText(string $field, mixed $value, int $limit): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $limit || preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new InvalidArgumentException("The unfreeze {$field} must be non-empty printable ASCII of at most {$limit} characters.");
        }

        return $value;
    }
}
