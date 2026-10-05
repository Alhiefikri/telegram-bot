<?php

namespace App\Services\UsdtBalance;

use App\Models\CustomerUsdtBalanceAccount;
use Illuminate\Support\Facades\DB;

/**
 * Freezes exactly the accounts a reconciliation reported as drifted, and only
 * after re-proving the drift under the same account row lock every ledger
 * posting takes. Freezing is not a money operation: no entry, projection or
 * accounting row is written, and a stale finding can never freeze a healthy
 * account. Unfreezing stays a deliberate operator action.
 */
final class UsdtBalanceDriftFreezer
{
    public function __construct(private UsdtBalanceReconciliation $reconciliation) {}

    public function freeze(UsdtBalanceDriftFinding $finding): UsdtBalanceFreezeOutcome
    {
        return DB::transaction(function () use ($finding): UsdtBalanceFreezeOutcome {
            $account = CustomerUsdtBalanceAccount::query()
                ->whereKey($finding->accountId)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                return UsdtBalanceFreezeOutcome::Missing;
            }

            if ($account->frozen_reason !== null) {
                return UsdtBalanceFreezeOutcome::AlreadyFrozen;
            }

            $current = $this->reconciliation->inspectAccount($finding->accountId);
            if ($current === null) {
                return UsdtBalanceFreezeOutcome::Stale;
            }

            $frozen = DB::table('customer_usdt_balance_accounts')
                ->where('id', $finding->accountId)
                ->whereNull('frozen_reason')
                ->update([
                    'frozen_reason' => $current->freezeReason(),
                    'updated_at' => now(),
                ]);

            return $frozen === 1
                ? UsdtBalanceFreezeOutcome::Frozen
                : UsdtBalanceFreezeOutcome::AlreadyFrozen;
        }, 3);
    }
}
