<?php

namespace App\Services\StoreBalance;

use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\StoreBalanceEntry;
use App\Models\SupplierFulfillment;
use App\Models\WarrantyClaim;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\RefundAllocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns every Store Balance mutation. Each economic effect is one immutable
 * ledger entry whose projection update happens in the same transaction, under
 * the Customer's account row lock, with database-enforced unique source and
 * idempotency claims.
 */
final class StoreBalanceLedger
{
    public function snapshot(Customer $customer): StoreBalanceSnapshot
    {
        // One statement keeps the projection and its newest entry inside a
        // single database snapshot, so a concurrent credit can never be
        // observed as a real ledger/projection divergence.
        $account = DB::table('customer_balance_accounts as accounts')
            ->where('accounts.customer_id', $customer->id)
            ->select('accounts.balance')
            ->selectSub(function ($query): void {
                $query->from('store_balance_entries')
                    ->select('balance_after')
                    ->whereColumn('store_balance_entries.customer_balance_account_id', 'accounts.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'latest_balance_after')
            ->first();

        if ($account === null) {
            return StoreBalanceSnapshot::available(0);
        }

        if ($account->latest_balance_after === null) {
            return (int) $account->balance === 0
                ? StoreBalanceSnapshot::available(0)
                : StoreBalanceSnapshot::unavailable();
        }

        return (int) $account->latest_balance_after === (int) $account->balance
            ? StoreBalanceSnapshot::available((int) $account->balance)
            : StoreBalanceSnapshot::unavailable();
    }

    /**
     * Acquire the Customer account lock inside a caller-owned transaction.
     */
    public function lockForTransaction(Customer $customer): CustomerBalanceAccount
    {
        return $this->lockAccount($customer);
    }

    public function credit(
        Customer $customer,
        int $amount,
        StoreBalanceEntryKind $kind,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): StoreBalanceMutationResult {
        if ($kind !== StoreBalanceEntryKind::TopUpCredit) {
            throw new InvalidArgumentException('Store Balance refunds must use the canonical refund operation.');
        }

        return $this->apply($customer, $kind, $amount, $sourceType, $sourceId, $idempotencyKey);
    }

    /**
     * Apply a top-up credit without scanning ledger history. The locked
     * projection cursor still prevents mutation on an account/projection
     * mismatch; full historical reconciliation remains an operator check.
     */
    public function creditTopUp(
        Customer $customer,
        int $amount,
        string $sourceId,
        string $idempotencyKey,
    ): StoreBalanceMutationResult {
        return $this->apply(
            $customer,
            StoreBalanceEntryKind::TopUpCredit,
            $amount,
            'balance_top_up',
            $sourceId,
            $idempotencyKey,
            validateProjectionHistory: false,
        );
    }

    public function creditOwnerCompensation(
        Customer $customer,
        int $amount,
        string $compensationId,
        string $idempotencyKey,
    ): StoreBalanceMutationResult {
        return $this->apply(
            $customer,
            StoreBalanceEntryKind::OwnerCompensationCredit,
            $amount,
            'owner_compensation',
            $compensationId,
            $idempotencyKey,
        );
    }

    /** Only a transaction-approved immutable request can authorize this posting. */
    public function creditApprovedAdjustment(BalanceAdjustmentRequest $request, ApprovedBalanceAdjustment $approval): StoreBalanceMutationResult
    {
        $approval->assertMatches($request);
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Adjustment approval requires its money transaction.');
        }
        $approved = BalanceAdjustmentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        if ($approved->state !== 'approved' || $approved->currency !== 'IDR'
            || $approved->fingerprint !== $request->fingerprint || in_array($approved->category, ['top_up_recovery', 'refund_recovery'], true)) {
            throw new InvalidArgumentException('Adjustment request is not approved for a new credit.');
        }

        return $this->apply(Customer::findOrFail($approved->customer_id), StoreBalanceEntryKind::AdminAdjustmentCredit,
            $approved->amount, 'balance_adjustment', $approved->id, 'balance-adjustment:'.$approved->id.':v1',
            validateProjectionHistory: false);
    }

    public function creditApprovedRefundAdjustment(BalanceAdjustmentRequest $request, OrderRefund $refund, ApprovedBalanceAdjustment $approval): StoreBalanceMutationResult
    {
        $request = BalanceAdjustmentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        $refund = OrderRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
        $approval->assertMatches($request);
        if (DB::transactionLevel() === 0 || $request->state !== 'approved' || $request->category !== 'refund_recovery'
            || $request->source_id !== $refund->id || $request->amount !== $refund->amount
            || (int) $request->customer_id !== (int) $refund->customer_id || $refund->currency !== 'IDR' || $refund->state !== 'requested') {
            throw new InvalidArgumentException('Refund recovery requires its approved original entitlement.');
        }

        return $this->apply(Customer::findOrFail($request->customer_id), StoreBalanceEntryKind::RefundCredit, $refund->amount,
            'order_refund', $refund->id, 'order-refund:'.$refund->id, validateProjectionHistory: false, refundOrderId: $refund->order_id);
    }

    public function debit(
        Customer $customer,
        int $amount,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): StoreBalanceMutationResult {
        return $this->apply($customer, StoreBalanceEntryKind::PurchaseDebit, $amount, $sourceType, $sourceId, $idempotencyKey);
    }

    public function refund(
        Customer $customer,
        int $amount,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): StoreBalanceMutationResult {
        if ($sourceType !== 'order') {
            throw new InvalidArgumentException('Store Balance refunds require an Order source.');
        }

        return DB::transaction(function () use ($customer, $amount, $sourceType, $sourceId, $idempotencyKey): StoreBalanceMutationResult {
            $account = $this->lockAccount($customer);
            $this->assertProjectionIsConsistent($account, $customer);
            if (! $this->hasPurchaseDebit($account, 'order', $sourceId, $amount)) {
                throw new RuntimeException('Cannot refund a Store Balance Order without its original debit.');
            }
            $partial = (int) StoreBalanceEntry::query()->where('customer_balance_account_id', $account->id)
                ->where('kind', StoreBalanceEntryKind::RefundCredit)->whereRaw('CAST(refund_order_id AS TEXT) = ?', [$sourceId])->sum('amount');
            $remaining = RefundAllocation::remaining($amount, $partial);
            if ($remaining === 0) {
                $existing = StoreBalanceEntry::query()->where('customer_balance_account_id', $account->id)
                    ->where('kind', StoreBalanceEntryKind::RefundCredit)->whereRaw('CAST(refund_order_id AS TEXT) = ?', [$sourceId])->latest('id')->firstOrFail();

                return StoreBalanceMutationResult::replayed($existing);
            }

            return $this->apply($customer, StoreBalanceEntryKind::RefundCredit, $remaining, $sourceType, $sourceId, $idempotencyKey);
        }, 5);
    }

    /** Credits a registered per-unit entitlement, capped by the original Order debit. */
    public function refundPartial(Customer $customer, OrderRefund $initial, string $idempotencyKey): StoreBalanceMutationResult
    {
        return DB::transaction(function () use ($customer, $initial, $idempotencyKey): StoreBalanceMutationResult {
            $account = $this->lockAccount($customer);
            $this->assertProjectionIsConsistent($account, $customer);
            $refund = OrderRefund::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ((int) $refund->customer_id !== (int) $customer->id || $refund->currency !== 'IDR' || ! in_array($refund->state, ['requested', 'confirmed'], true)) {
                throw new InvalidArgumentException('Invalid partial refund identity.');
            }
            $existing = $this->findEntry($account, StoreBalanceEntryKind::RefundCredit, $refund->amount, 'order_refund', (string) $refund->id, $idempotencyKey);
            if ($existing !== null) {
                if ($existing->refund_order_id !== $refund->order_id) {
                    throw new InvalidArgumentException('Invalid partial refund identity.');
                }

                return StoreBalanceMutationResult::replayed($existing);
            }
            $payment = Payment::query()->whereKey($refund->payment_id)->firstOrFail();
            $claim = $refund->warranty_claim_id === null ? null : WarrantyClaim::query()->whereKey($refund->warranty_claim_id)->firstOrFail();
            $authorized = false;
            if ($claim !== null) {
                $authorized = $claim->order_id === $refund->order_id && (int) $claim->customer_id === (int) $customer->id
                    && (int) $claim->supplier_account_unit_id === (int) $refund->supplier_account_unit_id
                    && $claim->state === 'approved' && $claim->verified_at !== null && $claim->settlement_choice === 'refund'
                    && $claim->unit->retail_amount === $refund->amount && $claim->unit->retail_currency === 'IDR';
            } elseif ($refund->supplier_fulfillment_id !== null) {
                $f = SupplierFulfillment::findOrFail($refund->supplier_fulfillment_id);
                $authorized = $f->order_id === $refund->order_id && $f->state === 'refund_pending' && $f->reserved_quantity === 0
                    && (int) $refund->missing_quantity === $f->quantity - MissingFulfillmentRefundService::acquired($f)
                    && $refund->amount === MissingFulfillmentRefundService::entitlement($f, 'IDR')
                    && ! $f->purchases()->whereIn('state', ['sending', 'awaiting_delivery', 'ambiguous', 'review'])->exists();
            }
            if ($payment->provider !== 'store_balance' || strtoupper($payment->method) !== 'STORE_BALANCE'
                || $payment->currency !== 'IDR' || $payment->status !== PaymentStatus::Paid || $payment->paid_at === null
                || $payment->order_id !== $refund->order_id || (int) $payment->order->customer_id !== (int) $customer->id
                || ! $authorized
                || ! $this->hasPurchaseDebit($account, 'order', $refund->order_id, $payment->amount)
                || StoreBalanceEntry::query()->where('source_type', 'order')->where('source_id', $refund->order_id)->where('kind', StoreBalanceEntryKind::RefundCredit)->exists()) {
                throw new InvalidArgumentException('Partial refund is not authorized by the original debit and verified entitlement.');
            }
            $alreadyRefunded = (int) StoreBalanceEntry::query()->where('customer_balance_account_id', $account->id)
                ->where('kind', StoreBalanceEntryKind::RefundCredit)->where('refund_order_id', $refund->order_id)->sum('amount');
            if ($refund->amount > RefundAllocation::remaining($payment->amount, $alreadyRefunded)) {
                throw new InvalidArgumentException('Refund exceeds original payment.');
            }

            return $this->apply($customer, StoreBalanceEntryKind::RefundCredit, $refund->amount, 'order_refund', (string) $refund->id, $idempotencyKey, refundOrderId: $refund->order_id);
        }, 5);
    }

    private function apply(
        Customer $customer,
        StoreBalanceEntryKind $kind,
        int $amount,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        bool $requiresPurchaseDebit = false,
        bool $validateProjectionHistory = true,
        ?string $refundOrderId = null,
    ): StoreBalanceMutationResult {
        $this->assertPositiveAmount($amount);
        $this->assertIdentity('source type', $sourceType, 64);
        $this->assertIdentity('source id', $sourceId, 64);
        $this->assertIdentity('idempotency key', $idempotencyKey, 191);

        try {
            return DB::transaction(function () use ($customer, $kind, $amount, $sourceType, $sourceId, $idempotencyKey, $requiresPurchaseDebit, $validateProjectionHistory, $refundOrderId): StoreBalanceMutationResult {
                $account = $this->lockAccount($customer);
                if ($validateProjectionHistory) {
                    $this->assertProjectionIsConsistent($account, $customer);
                } else {
                    $this->assertProjectionCursorIsConsistent($account, $customer);
                }
                $existing = $this->findEntry($account, $kind, $amount, $sourceType, $sourceId, $idempotencyKey);
                if ($existing !== null) {
                    return StoreBalanceMutationResult::replayed($existing);
                }
                if ($requiresPurchaseDebit && ! $this->hasPurchaseDebit($account, $sourceType, $sourceId, $amount)) {
                    throw new RuntimeException('Cannot refund a Store Balance Order without its original debit.');
                }

                $balance = (int) $account->balance;
                if ($kind->direction() > 0 && $amount > PHP_INT_MAX - $balance) {
                    throw new InvalidArgumentException('Store Balance balance exceeds supported integer range.');
                }

                $balanceAfter = $balance + ($kind->direction() * $amount);
                if ($balanceAfter < 0) {
                    throw new InsufficientStoreBalance('Store Balance cannot cover the requested amount.');
                }

                $entry = StoreBalanceEntry::query()->create([
                    'customer_balance_account_id' => $account->id,
                    'kind' => $kind,
                    'amount' => $amount,
                    'balance_after' => $balanceAfter,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                    'refund_order_id' => $refundOrderId,
                ]);

                $account->forceFill(['balance' => $balanceAfter])->save();

                return StoreBalanceMutationResult::applied($entry);
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            // The unique source/idempotency claims are the final guard for a
            // concurrent claim that committed after this transaction started.
            $existing = $this->findEntryAfterConflict($customer, $kind, $amount, $sourceType, $sourceId, $idempotencyKey);
            if ($existing !== null) {
                return StoreBalanceMutationResult::replayed($existing);
            }

            throw $exception;
        }
    }

    /**
     * Lock the Customer account row, creating it on first use. A concurrent
     * create is resolved by the unique customer claim and a bounded retry, so
     * a losing transaction never observes a half-created account.
     */
    private function lockAccount(Customer $customer): CustomerBalanceAccount
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $account = CustomerBalanceAccount::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($account !== null) {
                return $account;
            }

            try {
                return DB::transaction(fn (): CustomerBalanceAccount => CustomerBalanceAccount::query()
                    ->create(['customer_id' => $customer->id])
                    ->refresh());
            } catch (UniqueConstraintViolationException) {
                // Another transaction owns the create; retry to lock the row it
                // committed. If it rolled back, the next attempt creates it.
            }
        }

        throw new RuntimeException("Unable to lock the Store Balance account for Customer {$customer->id}.");
    }

    private function findEntry(
        CustomerBalanceAccount $account,
        StoreBalanceEntryKind $kind,
        int $amount,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): ?StoreBalanceEntry {
        $byIdempotency = StoreBalanceEntry::query()
            ->where('customer_balance_account_id', $account->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        $bySource = StoreBalanceEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('kind', $kind)
            ->first();

        foreach ([$byIdempotency, $bySource] as $entry) {
            if ($entry === null) {
                continue;
            }

            if ((int) $entry->customer_balance_account_id !== (int) $account->id
                || $entry->kind !== $kind
                || (int) $entry->amount !== $amount
                || $entry->source_type !== $sourceType
                || $entry->source_id !== $sourceId) {
                throw new InvalidArgumentException('Store Balance economic identity was already claimed with different values.');
            }
        }

        if ($byIdempotency !== null && $bySource !== null && $byIdempotency->id !== $bySource->id) {
            throw new InvalidArgumentException('Store Balance idempotency and source claims refer to different entries.');
        }

        return $byIdempotency ?? $bySource;
    }

    private function assertProjectionCursorIsConsistent(CustomerBalanceAccount $account, Customer $customer): void
    {
        $latestBalance = StoreBalanceEntry::query()
            ->where('customer_balance_account_id', $account->id)
            ->latest('id')
            ->value('balance_after');
        $ledgerBalance = $latestBalance === null ? 0 : (int) $latestBalance;

        if ((int) $account->balance !== $ledgerBalance) {
            throw new StoreBalanceProjectionMismatchException(
                customerId: (int) $customer->getKey(),
                projectedBalance: (int) $account->balance,
                ledgerBalance: $ledgerBalance,
            );
        }
    }

    private function assertProjectionIsConsistent(CustomerBalanceAccount $account, Customer $customer): void
    {
        $ledgerBalance = (int) StoreBalanceEntry::query()
            ->where('customer_balance_account_id', $account->id)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN kind = ? THEN -amount ELSE amount END), 0) AS ledger_balance',
                [StoreBalanceEntryKind::PurchaseDebit->value],
            )
            ->value('ledger_balance');

        if ((int) $account->balance !== $ledgerBalance) {
            throw new StoreBalanceProjectionMismatchException(
                customerId: (int) $customer->getKey(),
                projectedBalance: (int) $account->balance,
                ledgerBalance: $ledgerBalance,
            );
        }
    }

    private function hasPurchaseDebit(
        CustomerBalanceAccount $account,
        string $sourceType,
        string $sourceId,
        int $amount,
    ): bool {
        return StoreBalanceEntry::query()
            ->where('customer_balance_account_id', $account->id)
            ->where('kind', StoreBalanceEntryKind::PurchaseDebit)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('amount', $amount)
            ->exists();
    }

    private function findEntryAfterConflict(
        Customer $customer,
        StoreBalanceEntryKind $kind,
        int $amount,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): ?StoreBalanceEntry {
        $account = CustomerBalanceAccount::query()
            ->where('customer_id', $customer->getKey())
            ->first();

        if ($account === null) {
            $foreignEntry = StoreBalanceEntry::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();

            if ($foreignEntry !== null) {
                throw new InvalidArgumentException('Store Balance source belongs to another Customer.');
            }

            return null;
        }

        return $this->findEntry(
            account: $account,
            kind: $kind,
            amount: $amount,
            sourceType: $sourceType,
            sourceId: $sourceId,
            idempotencyKey: $idempotencyKey,
        );
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Store Balance amounts must be positive integer IDR.');
        }
    }

    private function assertIdentity(string $label, string $value, int $maxLength): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Store Balance {$label} must be a non-empty value of at most {$maxLength} characters.");
        }
    }
}
