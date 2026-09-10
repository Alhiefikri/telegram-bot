<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Exceptions\InsufficientStoreBalance;
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
        if (! $kind->isCredit()) {
            throw new InvalidArgumentException('A Store Balance credit requires a credit kind.');
        }

        return $this->apply($customer, $kind, $amount, $sourceType, $sourceId, $idempotencyKey);
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
    ): StoreBalanceMutationResult {
        $this->assertPositiveAmount($amount);
        $this->assertIdentity('source type', $sourceType, 64);
        $this->assertIdentity('source id', $sourceId, 64);
        $this->assertIdentity('idempotency key', $idempotencyKey, 191);

        try {
            return DB::transaction(function () use ($customer, $kind, $amount, $sourceType, $sourceId, $idempotencyKey, $requiresPurchaseDebit): StoreBalanceMutationResult {
                $account = $this->lockAccount($customer);
                $existing = $this->findEntry($account, $kind, $sourceType, $sourceId, $idempotencyKey);
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
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The unique source/idempotency claims are the final guard for a
            // concurrent claim that committed after this transaction started.
            $existing = $this->findEntryAfterConflict($customer, $kind, $sourceType, $sourceId, $idempotencyKey);
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
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): ?StoreBalanceEntry {
        return StoreBalanceEntry::query()
            ->where(function ($query) use ($account, $idempotencyKey): void {
                $query->where('customer_balance_account_id', $account->id)
                    ->where('idempotency_key', $idempotencyKey);
            })
            ->orWhere(function ($query) use ($kind, $sourceType, $sourceId): void {
                $query->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('kind', $kind);
            })
            ->first();
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
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
    ): ?StoreBalanceEntry {
        $entry = StoreBalanceEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('kind', $kind)
            ->first();

        if ($entry !== null) {
            return $entry;
        }

        $accountId = CustomerBalanceAccount::query()
            ->where('customer_id', $customer->id)
            ->value('id');

        if ($accountId === null) {
            return null;
        }

        return StoreBalanceEntry::query()
            ->where('customer_balance_account_id', $accountId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
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
