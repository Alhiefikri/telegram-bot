<?php

namespace App\Services\StoreBalance\TopUp;

use App\Models\StoreBalanceTopUp;

/**
 * One Store Balance top-up payment rail.
 *
 * A rail owns provider create/recovery/status work and the provider
 * vocabulary that decides payability, plus the instruction attachment for its
 * own presentation. It never credits a balance: settlement stays with
 * StoreBalanceTopUpService and the ledger.
 */
interface StoreBalanceTopUpLifecycle
{
    public function provider(): string;

    public function classify(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult;

    public function presentation(StoreBalanceTopUp $topUp, bool $refresh = true): StoreBalanceTopUpPresentation;

    public function lifecycle(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult;

    public function reconcile(StoreBalanceTopUp $topUp): StoreBalanceTopUpReconciliation;

    public function instructionAttachment(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
    ): StoreBalanceTopUpInstructionAttachment;
}
