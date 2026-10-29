<?php

namespace App\Services\UsdtBalance;

use App\Enums\BalanceOperationMetric;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\InsufficientUsdtBalance;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Exceptions\UsdtBalanceProjectionMismatchException;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\UsdtTopUpReviewRequest;
use App\Models\UsdtTransferRecoveryRequest;
use App\Services\Operations\BalanceOperationEventRecorder;
use App\Services\Operations\BalanceOperationRetry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns every Customer USDT Balance mutation. Each typed economic source
 * produces exactly one immutable entry whose projection cursor, accounting
 * event and fingerprint commit in the same transaction, under the Customer's
 * account row lock, with database-enforced unique sequence, idempotency and
 * source claims. There is no arbitrary public credit API.
 */
final class CustomerUsdtBalanceLedger
{
    public function __construct(
        private readonly BalanceOperationEventRecorder $events,
        private readonly BalanceOperationRetry $retry,
    ) {}

    public function snapshot(Customer $customer): UsdtBalanceSnapshot
    {
        // One statement keeps the projection and its newest entry inside a
        // single database snapshot, so a concurrent posting can never be
        // observed as a real ledger/projection divergence.
        $account = DB::table('customer_usdt_balance_accounts as accounts')
            ->where('accounts.customer_id', $customer->id)
            ->select(['accounts.balance_micro', 'accounts.version', 'accounts.frozen_reason'])
            ->selectSub(function ($query): void {
                $query->from('customer_usdt_balance_entries')
                    ->select('balance_after_micro')
                    ->whereColumn('customer_usdt_balance_entries.customer_usdt_balance_account_id', 'accounts.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'latest_balance_after')
            ->selectSub(function ($query): void {
                $query->from('customer_usdt_balance_entries')
                    ->select('account_sequence')
                    ->whereColumn('customer_usdt_balance_entries.customer_usdt_balance_account_id', 'accounts.id')
                    ->orderByDesc('id')
                    ->limit(1);
            }, 'latest_account_sequence')
            ->first();

        if ($account === null) {
            return UsdtBalanceSnapshot::available(0);
        }

        if ($account->latest_balance_after === null) {
            return (int) $account->version === 0 && (int) $account->balance_micro === 0
                ? UsdtBalanceSnapshot::available(0, $account->frozen_reason)
                : UsdtBalanceSnapshot::unavailable($account->frozen_reason);
        }

        if ((int) $account->latest_balance_after !== (int) $account->balance_micro
            || (int) $account->latest_account_sequence !== (int) $account->version) {
            return UsdtBalanceSnapshot::unavailable($account->frozen_reason);
        }

        return UsdtBalanceSnapshot::available((int) $account->balance_micro, $account->frozen_reason);
    }

    /**
     * Acquire the Customer account lock inside a caller-owned transaction and
     * refuse a frozen account or a drifted projection cursor. The account row
     * alone is returned; no caller may write its projection directly.
     */
    public function lockForTransaction(Customer $customer): CustomerUsdtBalanceAccount
    {
        $account = $this->lockAccount($customer);
        $this->assertMutable($account, $customer);

        return $account;
    }

    public function creditVerifiedTopUp(Customer $customer, VerifiedUsdtFunding $source, string $idempotencyKey): UsdtBalanceMutationResult
    {
        return $this->apply($customer, $source, $idempotencyKey, function () use ($customer, $source): void {
            if ($source->reviewRequestId === null) {
                return;
            }
            $reviewModel = UsdtTopUpReviewRequest::query()->whereKey($source->reviewRequestId)
                ->where('top_up_id', $source->topUpId)->first();
            if ($reviewModel === null || $source->reviewAuthority === null) {
                throw new InvalidArgumentException('Reviewed funding requires password-confirmed typed authority.');
            }
            $source->reviewAuthority->assertMatches($reviewModel);
            $review = $reviewModel;
            $target = DB::table('customer_usdt_top_ups')->where('id', $source->topUpId)->where('customer_id', $customer->id)->first();
            $receipt = DB::table('external_money_receipts')->where('id', $source->receiptId)
                ->where('target_id', $source->topUpId)->where('target_type', 'customer_usdt_top_up')->where('currency', 'USDT')->first();
            $evidence = DB::table('customer_usdt_top_up_evidence')->where('id', $source->evidenceVersion)
                ->where('top_up_id', $source->topUpId)->where('status', 'verified')->first();
            if ($review === null || $target === null || $receipt === null || $evidence === null
                || ! in_array($review->decision, ['settled', 'resolved_by_compensation'], true)
                || $review->expires_at->isPast()
                || $receipt->status !== 'claimed' || $receipt->beneficiary !== 'customer:'.$customer->id
                || (int) $review->amount_micro - (int) $target->fee_micro !== $source->amountMicro()
                || UsdtMicroAmount::fromRawTokenUnits($receipt->amount_units, (int) $receipt->decimals)->units() !== (int) $review->amount_micro) {
                throw new InvalidArgumentException('Reviewed funding requires its exact globally claimed verified source.');
            }
        });
    }

    public function creditVerifiedRecovery(Customer $customer, VerifiedUsdtRecovery $source): UsdtBalanceMutationResult
    {
        return $this->apply($customer, $source, 'usdt-recovery:'.$source->requestId, function () use ($customer, $source): void {
            $request = UsdtTransferRecoveryRequest::query()->findOrFail($source->requestId);
            $source->authority->assertMatches($request);
            $receipt = DB::table('external_money_receipts')->where('id', $source->receiptId)
                ->where('target_type', 'customer_usdt_recovery')->where('target_id', $request->id)
                ->where('beneficiary', 'customer:'.$customer->id)->where('currency', 'USDT')
                ->where('status', 'claimed')->first();
            if ((int) $request->customer_id !== (int) $customer->id || $request->expires_at->isPast()
                || $request->amount_micro !== $source->amountMicro() || $receipt === null
                || $receipt->tx_hash !== $request->transaction_hash
                || UsdtMicroAmount::fromRawTokenUnits($receipt->amount_units, (int) $receipt->decimals)->units() !== $source->amountMicro()) {
                throw new InvalidArgumentException('Recovery requires its exact password-authorized verified receipt.');
            }
        });
    }

    public function debitPurchase(Customer $customer, UsdtPurchase $source, string $idempotencyKey): UsdtBalanceMutationResult
    {
        return $this->apply($customer, $source, $idempotencyKey);
    }

    public function refundEntitlement(Customer $customer, UsdtRefund $source, string $idempotencyKey): UsdtBalanceMutationResult
    {
        return $this->apply($customer, $source, $idempotencyKey, function (CustomerUsdtBalanceAccount $account) use ($source): void {
            app(UsdtOrderRefundService::class)->validate($account, $source);
        });
    }

    public function reverseApprovedEntry(Customer $customer, ApprovedUsdtReversal $source, string $idempotencyKey): UsdtBalanceMutationResult
    {
        return $this->apply($customer, $source, $idempotencyKey, function (CustomerUsdtBalanceAccount $account) use ($source): void {
            $original = CustomerUsdtBalanceEntry::query()->whereKey($source->originalEntryId)->first();
            if ($original === null
                || (int) $original->customer_usdt_balance_account_id !== (int) $account->id
                || ! $original->kind->isCredit()
                || (int) $original->amount_micro !== $source->amountMicro()) {
                throw new InvalidArgumentException('USDT reversal requires the full original credit entry.');
            }
            if (CustomerUsdtBalanceEntry::query()
                ->where('original_entry_id', $original->id)
                ->where('kind', UsdtBalanceEntryKind::ReversalDebit)
                ->exists()) {
                throw new InvalidArgumentException('USDT reversal was already posted for the original entry.');
            }
        });
    }

    /**
     * @param  (callable(CustomerUsdtBalanceAccount): void)|null  $validate
     */
    private function apply(
        Customer $customer,
        UsdtBalanceSource $source,
        string $idempotencyKey,
        ?callable $validate = null,
    ): UsdtBalanceMutationResult {
        $this->assertIdentity('idempotency key', $idempotencyKey, 191);
        if ((int) $customer->getKey() !== $source->customerId) {
            throw new InvalidArgumentException('Customer USDT Balance source belongs to another Customer.');
        }
        $fingerprint = $source->fingerprint();

        try {
            $result = $this->retry->transaction(function () use ($customer, $source, $idempotencyKey, $fingerprint, $validate): UsdtBalanceMutationResult {
                $account = $this->lockAccount($customer);

                // A matching replay is a pure resolution of the recorded
                // economic effect: it must return the existing entry even when
                // the account is frozen or its projection drifted afterwards.
                $existing = $this->findEntry($account, $source, $fingerprint, $idempotencyKey);
                if ($existing !== null) {
                    return UsdtBalanceMutationResult::replayed($existing);
                }

                $this->assertMutable($account, $customer);
                if ($validate !== null) {
                    $validate($account);
                }

                $kind = $source->kind();
                $amount = $source->amountMicro();
                $before = (int) $account->balance_micro;
                if ($kind->isCredit() && $amount > PHP_INT_MAX - $before) {
                    throw new InvalidArgumentException('Customer USDT Balance exceeds the supported integer range.');
                }
                $after = $before + ($kind->direction() * $amount);
                if ($after < 0) {
                    throw new InsufficientUsdtBalance('Customer USDT Balance cannot cover the requested amount.');
                }

                $entry = CustomerUsdtBalanceEntry::query()->create([
                    'customer_usdt_balance_account_id' => $account->id,
                    'account_sequence' => (int) $account->version + 1,
                    'kind' => $kind,
                    'amount_micro' => $amount,
                    'balance_after_micro' => $after,
                    'source_type' => $source->sourceType(),
                    'source_id' => $source->sourceId(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'original_entry_id' => $source->originalEntryId(),
                    'refund_order_id' => $source->refundOrderId(),
                    'created_at' => now(),
                ]);

                $update = DB::table('customer_usdt_balance_accounts')->where('id', $account->id);
                if ($kind->isCredit()) {
                    $update->where('balance_micro', $before);
                } else {
                    $update->where('balance_micro', '>=', $amount);
                }
                $affected = $update->update([
                    'balance_micro' => $after,
                    'last_entry_id' => $entry->id,
                    'version' => (int) $account->version + 1,
                ]);
                if ($affected !== 1) {
                    if ($kind->isCredit()) {
                        throw new UsdtBalanceProjectionMismatchException((int) $customer->getKey(), (int) $account->balance_micro, $before);
                    }

                    throw new InsufficientUsdtBalance('Customer USDT Balance cannot cover the requested amount.');
                }

                $this->writeAccountingEvent($entry, $source, $fingerprint);

                return UsdtBalanceMutationResult::applied($entry);
            }, 5);
        } catch (InsufficientUsdtBalance $exception) {
            $this->events->record(BalanceOperationMetric::LedgerOutcome, 'insufficient_funds');

            throw $exception;
        } catch (UsdtBalanceFrozenException $exception) {
            $this->events->record(BalanceOperationMetric::LedgerOutcome, 'frozen');

            throw $exception;
        } catch (UsdtBalanceProjectionMismatchException $exception) {
            $this->events->record(BalanceOperationMetric::LedgerOutcome, 'projection_drift');

            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            // The unique source/sequence/idempotency claims are the final
            // guard for a concurrent claim that committed after this
            // transaction started.
            $existing = $this->findEntryAfterConflict($customer, $source, $fingerprint, $idempotencyKey);
            if ($existing === null) {
                $this->events->record(BalanceOperationMetric::LedgerOutcome, 'identity_conflict');

                throw $exception;
            }

            $this->events->record(BalanceOperationMetric::LedgerOutcome, 'replayed');

            return UsdtBalanceMutationResult::replayed($existing);
        }

        $this->events->record(BalanceOperationMetric::LedgerOutcome, $result->applied ? 'posted' : 'replayed');

        return $result;
    }

    /**
     * Lock the Customer account row, creating it on first use. The conflict
     * safe insert followed by a real row lock means a concurrent create can
     * never expose a half-created account to a losing transaction.
     */
    private function lockAccount(Customer $customer): CustomerUsdtBalanceAccount
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $account = CustomerUsdtBalanceAccount::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($account !== null) {
                return $account;
            }

            DB::table('customer_usdt_balance_accounts')->insertOrIgnore([
                'customer_id' => $customer->id,
                'currency' => UsdtMicroAmount::CURRENCY,
                'balance_micro' => 0,
                'version' => 0,
                'last_entry_id' => null,
                'frozen_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        throw new RuntimeException("Unable to lock the Customer USDT Balance account for Customer {$customer->id}.");
    }

    private function findEntry(
        CustomerUsdtBalanceAccount $account,
        UsdtBalanceSource $source,
        string $fingerprint,
        string $idempotencyKey,
    ): ?CustomerUsdtBalanceEntry {
        $byIdempotency = CustomerUsdtBalanceEntry::query()
            ->where('customer_usdt_balance_account_id', $account->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        $bySource = CustomerUsdtBalanceEntry::query()
            ->where('source_type', $source->sourceType())
            ->where('source_id', $source->sourceId())
            ->where('kind', $source->kind())
            ->first();

        foreach ([$byIdempotency, $bySource] as $entry) {
            if ($entry === null) {
                continue;
            }

            if ((int) $entry->customer_usdt_balance_account_id !== (int) $account->id
                || $entry->kind !== $source->kind()
                || (int) $entry->amount_micro !== $source->amountMicro()
                || $entry->source_type !== $source->sourceType()
                || $entry->source_id !== $source->sourceId()
                || $entry->request_fingerprint !== $fingerprint
                || (int) ($entry->original_entry_id ?? 0) !== (int) ($source->originalEntryId() ?? 0)
                || (string) ($entry->refund_order_id ?? '') !== (string) ($source->refundOrderId() ?? '')) {
                throw new InvalidArgumentException('Customer USDT Balance economic identity was already claimed with different values.');
            }
        }

        if ($byIdempotency !== null && $bySource !== null && $byIdempotency->id !== $bySource->id) {
            throw new InvalidArgumentException('Customer USDT Balance idempotency and source claims refer to different entries.');
        }

        return $byIdempotency ?? $bySource;
    }

    private function assertMutable(CustomerUsdtBalanceAccount $account, Customer $customer): void
    {
        if ($account->frozen_reason !== null) {
            throw new UsdtBalanceFrozenException((int) $customer->getKey(), (string) $account->frozen_reason);
        }

        $this->assertProjectionCursorIsConsistent($account, $customer);
    }

    private function assertProjectionCursorIsConsistent(CustomerUsdtBalanceAccount $account, Customer $customer): void
    {
        $version = (int) $account->version;
        $balance = (int) $account->balance_micro;

        if ($version === 0) {
            if ($account->last_entry_id === null && $balance === 0) {
                return;
            }

            throw new UsdtBalanceProjectionMismatchException((int) $customer->getKey(), $balance, 0);
        }

        $entry = $account->last_entry_id === null
            ? null
            : CustomerUsdtBalanceEntry::query()->whereKey($account->last_entry_id)->first();
        if ($entry === null
            || (int) $entry->customer_usdt_balance_account_id !== (int) $account->id
            || (int) $entry->account_sequence !== $version
            || (int) $entry->balance_after_micro !== $balance) {
            throw new UsdtBalanceProjectionMismatchException(
                (int) $customer->getKey(),
                $balance,
                $entry === null ? 0 : (int) $entry->balance_after_micro,
            );
        }
    }

    private function writeAccountingEvent(CustomerUsdtBalanceEntry $entry, UsdtBalanceSource $source, string $fingerprint): void
    {
        if ($source->kind() === UsdtBalanceEntryKind::ReversalDebit) {
            $original = DB::table('customer_usdt_balance_accounting_events')
                ->where('customer_usdt_balance_entry_id', $source->originalEntryId())
                ->first(['debit_account', 'credit_account']);
            if ($original === null) {
                throw new InvalidArgumentException('USDT reversal requires the original accounting event.');
            }
            $debit = $original->credit_account;
            $credit = $original->debit_account;
        } else {
            [$debit, $credit] = match ($source->kind()) {
                UsdtBalanceEntryKind::FundingCredit => ['usdt_settlement_asset', 'customer_usdt_liability'],
                UsdtBalanceEntryKind::PurchaseDebit => ['customer_usdt_liability', 'usdt_sale'],
                UsdtBalanceEntryKind::RefundCredit => ['usdt_sale_refund', 'customer_usdt_liability'],
                UsdtBalanceEntryKind::ReversalDebit => throw new InvalidArgumentException('USDT reversal requires its original accounting event.'),
            };
        }

        DB::table('customer_usdt_balance_accounting_events')->insert([
            'customer_usdt_balance_entry_id' => $entry->id,
            'currency' => UsdtMicroAmount::CURRENCY,
            'amount_micro' => $entry->amount_micro,
            'debit_account' => $debit,
            'credit_account' => $credit,
            'source_fingerprint' => $fingerprint,
            'created_at' => now(),
        ]);
    }

    private function findEntryAfterConflict(
        Customer $customer,
        UsdtBalanceSource $source,
        string $fingerprint,
        string $idempotencyKey,
    ): ?CustomerUsdtBalanceEntry {
        $account = CustomerUsdtBalanceAccount::query()
            ->where('customer_id', $customer->getKey())
            ->first();

        if ($account === null) {
            $foreignEntry = CustomerUsdtBalanceEntry::query()
                ->where('source_type', $source->sourceType())
                ->where('source_id', $source->sourceId())
                ->first();

            if ($foreignEntry !== null) {
                throw new InvalidArgumentException('Customer USDT Balance source belongs to another Customer.');
            }

            return null;
        }

        return $this->findEntry($account, $source, $fingerprint, $idempotencyKey);
    }

    private function assertIdentity(string $label, string $value, int $maxLength): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Customer USDT Balance {$label} must be a non-empty value of at most {$maxLength} characters.");
        }
    }
}
