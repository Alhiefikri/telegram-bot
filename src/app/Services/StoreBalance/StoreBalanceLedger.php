<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\StoreBalanceEntry;
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

        return $this->apply(
            $customer,
            StoreBalanceEntryKind::RefundCredit,
            $amount,
            $sourceType,
            $sourceId,
            $idempotencyKey,
            requiresPurchaseDebit: true,
        );
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
    ): StoreBalanceMutationResult {
        $this->assertPositiveAmount($amount);
        $this->assertIdentity('source type', $sourceType, 64);
        $this->assertIdentity('source id', $sourceId, 64);
        $this->assertIdentity('idempotency key', $idempotencyKey, 191);

        try {
            return DB::transaction(function () use ($customer, $kind, $amount, $sourceType, $sourceId, $idempotencyKey, $requiresPurchaseDebit, $validateProjectionHistory): StoreBalanceMutationResult {
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

                $balanceAfter = (int) $account->balance + ($kind->direction() * $amount);
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
