<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\InsufficientUsdtBalance;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Exceptions\UsdtBalanceProjectionMismatchException;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\Order;
use App\Models\StoreBalanceEntry;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\UsdtBalance\ApprovedUsdtReversal;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtBalance\UsdtRefund;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class CustomerUsdtBalanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): CustomerUsdtBalanceLedger
    {
        return app(CustomerUsdtBalanceLedger::class);
    }

    private function funding(int $customerId, int $amountMicro = 5_000_000, string $topUpId = 'topup-1', string $receiptId = 'receipt-1'): VerifiedUsdtFunding
    {
        return new VerifiedUsdtFunding($customerId, UsdtMicroAmount::fromUnits($amountMicro), $topUpId, $receiptId, 'v1');
    }

    public function test_funding_credit_appends_one_immutable_entry_and_moves_the_projection_and_accounting_atomically(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902101]);
        $source = $this->funding($customer->id, 2_500_000);

        $result = $this->ledger()->creditVerifiedTopUp($customer, $source, 'customer_usdt_top_up:topup-1:credit');

        $this->assertTrue($result->applied);
        $this->assertSame(UsdtBalanceEntryKind::FundingCredit, $result->kind);
        $this->assertSame(2_500_000, $result->amountMicro);
        $this->assertSame(2_500_000, $result->balanceAfterMicro);

        $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(2_500_000, $account->balance_micro);
        $this->assertSame('USDT', $account->currency);
        $this->assertSame(1, $account->version);
        $this->assertNull($account->frozen_reason);

        $entry = CustomerUsdtBalanceEntry::query()->sole();
        $this->assertSame($result->entryId, $entry->id);
        $this->assertSame($account->id, $entry->customer_usdt_balance_account_id);
        $this->assertSame(1, $entry->account_sequence);
        $this->assertSame(UsdtBalanceEntryKind::FundingCredit, $entry->kind);
        $this->assertSame(2_500_000, $entry->amount_micro);
        $this->assertSame(2_500_000, $entry->balance_after_micro);
        $this->assertSame('customer_usdt_top_up', $entry->source_type);
        $this->assertSame('topup-1', $entry->source_id);
        $this->assertSame('customer_usdt_top_up:topup-1:credit', $entry->idempotency_key);
        $this->assertSame($source->fingerprint(), $entry->request_fingerprint);
        $this->assertSame($entry->id, $account->last_entry_id);
        $this->assertNotNull($entry->created_at);

        $event = DB::table('customer_usdt_balance_accounting_events')->sole();
        $this->assertSame($entry->id, $event->customer_usdt_balance_entry_id);
        $this->assertSame('USDT', $event->currency);
        $this->assertSame(2_500_000, (int) $event->amount_micro);
        $this->assertSame('usdt_settlement_asset', $event->debit_account);
        $this->assertSame('customer_usdt_liability', $event->credit_account);
        $this->assertSame($source->fingerprint(), $event->source_fingerprint);

        $snapshot = $this->ledger()->snapshot($customer);
        $this->assertTrue($snapshot->isAvailable());
        $this->assertSame(2_500_000, $snapshot->balanceMicro());
        $this->assertSame('USDT', $snapshot->currency());
        $this->assertFalse($snapshot->isFrozen());
    }

    public function test_matching_idempotency_or_source_replay_returns_the_existing_entry_without_a_second_posting(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902102]);
        $source = $this->funding($customer->id);

        $first = $this->ledger()->creditVerifiedTopUp($customer, $source, 'usdt:replay-1');
        $byIdempotency = $this->ledger()->creditVerifiedTopUp($customer, $source, 'usdt:replay-1');
        $bySource = $this->ledger()->creditVerifiedTopUp($customer, $source, 'usdt:replay-2');

        $this->assertTrue($first->applied);
        $this->assertFalse($byIdempotency->applied);
        $this->assertFalse($bySource->applied);
        $this->assertSame($first->entryId, $byIdempotency->entryId);
        $this->assertSame($first->entryId, $bySource->entryId);
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_accounting_events')->count());
        $this->assertSame(5_000_000, $this->ledger()->snapshot($customer)->balanceMicro());
    }

    public function test_reusing_a_claim_with_changed_amount_source_or_fingerprint_conflicts(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902103]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:conflict');

        $conflicts = [
            fn () => $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 6_000_000), 'usdt:conflict'),
            fn () => $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 5_000_000, 'topup-2'), 'usdt:conflict'),
            fn () => $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 5_000_000, 'topup-1', 'receipt-2'), 'usdt:conflict-2'),
            fn () => $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 4_000_000, 'topup-1'), 'usdt:conflict-3'),
        ];

        foreach ($conflicts as $conflict) {
            try {
                $conflict();
                $this->fail('Expected the conflicting claim to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(5_000_000, $this->ledger()->snapshot($customer)->balanceMicro());
    }

    public function test_sources_cannot_post_to_another_customer_and_one_account_is_kept_per_customer(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902104]);
        $other = Customer::factory()->create(['telegram_id' => 902105]);
        $ledger = $this->ledger();

        try {
            $ledger->creditVerifiedTopUp($other, $this->funding($customer->id), 'usdt:foreign-1');
            $this->fail('Expected the foreign source to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 1_000_000, 'topup-a'), 'usdt:account-a');
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 2_000_000, 'topup-b'), 'usdt:account-b');

        $this->assertSame(1, CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(3_000_000, $ledger->snapshot($customer)->balanceMicro());
        $this->assertSame([1, 2], CustomerUsdtBalanceEntry::query()->orderBy('id')->pluck('account_sequence')->all());
    }

    public function test_debit_purchase_reduces_the_projection_and_replays_idempotently(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902106]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:fund');

        $debit = new UsdtPurchase($customer->id, UsdtMicroAmount::fromUnits(2_000_000), 'order-1', 'payment-1');
        $first = $ledger->debitPurchase($customer, $debit, 'order:order-1:debit');
        $replay = $ledger->debitPurchase($customer, $debit, 'order:order-1:debit');

        $this->assertTrue($first->applied);
        $this->assertFalse($replay->applied);
        $this->assertSame($first->entryId, $replay->entryId);
        $this->assertSame(3_000_000, $ledger->snapshot($customer)->balanceMicro());

        $entry = CustomerUsdtBalanceEntry::query()->where('kind', UsdtBalanceEntryKind::PurchaseDebit)->sole();
        $this->assertSame(2_000_000, $entry->amount_micro);
        $this->assertSame(3_000_000, $entry->balance_after_micro);
        $event = DB::table('customer_usdt_balance_accounting_events')->where('customer_usdt_balance_entry_id', $entry->id)->sole();
        $this->assertSame('customer_usdt_liability', $event->debit_account);
        $this->assertSame('usdt_sale', $event->credit_account);
    }

    public function test_insufficient_debit_changes_no_money_and_creates_no_account(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902107]);

        try {
            $this->ledger()->debitPurchase($customer, new UsdtPurchase($customer->id, UsdtMicroAmount::fromUnits(1), 'order-1', 'payment-1'), 'order:order-1:debit');
            $this->fail('Expected the insufficient debit to be rejected.');
        } catch (InsufficientUsdtBalance) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, CustomerUsdtBalanceAccount::query()->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_accounting_events')->count());
    }

    public function test_refund_requires_the_original_purchase_debit_and_caps_cumulative_credits(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902108]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:fund');
        $debit = $ledger->debitPurchase($customer, new UsdtPurchase($customer->id, UsdtMicroAmount::fromUnits(2_500_000), (string) $order->id, 'payment-1'), 'order:order-1:debit');

        $refund = new UsdtRefund($customer->id, UsdtMicroAmount::fromUnits(1_500_000), 'refund-1', (string) $order->id, $debit->entryId);
        $first = $ledger->refundEntitlement($customer, $refund, 'order-refund:refund-1');

        $this->assertTrue($first->applied);
        $this->assertSame(4_000_000, $ledger->snapshot($customer)->balanceMicro());

        $event = DB::table('customer_usdt_balance_accounting_events')
            ->where('customer_usdt_balance_entry_id', $first->entryId)->sole();
        $this->assertSame('usdt_sale_refund', $event->debit_account);
        $this->assertSame('customer_usdt_liability', $event->credit_account);

        try {
            $ledger->refundEntitlement($customer, new UsdtRefund($customer->id, UsdtMicroAmount::fromUnits(1_500_000), 'refund-2', (string) $order->id, $debit->entryId), 'order-refund:refund-2');
            $this->fail('Expected the cumulative cap to reject the second refund.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $ledger->refundEntitlement($customer, new UsdtRefund($customer->id, UsdtMicroAmount::fromUnits(1_500_000), 'refund-3', 'order-2', $debit->entryId), 'order-refund:refund-3');
            $this->fail('Expected a foreign Order refund to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(4_000_000, $ledger->snapshot($customer)->balanceMicro());
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->where('kind', UsdtBalanceEntryKind::RefundCredit)->count());
    }

    public function test_reversal_is_full_linked_and_allowed_only_once_with_mirrored_accounting(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902109]);
        $ledger = $this->ledger();
        $credit = $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:fund');

        try {
            $ledger->reverseApprovedEntry($customer, new ApprovedUsdtReversal($customer->id, UsdtMicroAmount::fromUnits(4_999_999), $credit->entryId, 'approval-1'), 'usdt:reversal');
            $this->fail('Expected a partial reversal to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $first = $ledger->reverseApprovedEntry($customer, new ApprovedUsdtReversal($customer->id, UsdtMicroAmount::fromUnits(5_000_000), $credit->entryId, 'approval-1'), 'usdt:reversal');
        $this->assertTrue($first->applied);
        $this->assertSame(0, $ledger->snapshot($customer)->balanceMicro());

        $entry = CustomerUsdtBalanceEntry::query()->whereKey($first->entryId)->sole();
        $this->assertSame(UsdtBalanceEntryKind::ReversalDebit, $entry->kind);
        $this->assertSame($credit->entryId, $entry->original_entry_id);
        $event = DB::table('customer_usdt_balance_accounting_events')->where('customer_usdt_balance_entry_id', $entry->id)->sole();
        $this->assertSame('customer_usdt_liability', $event->debit_account);
        $this->assertSame('usdt_settlement_asset', $event->credit_account);

        try {
            $ledger->reverseApprovedEntry($customer, new ApprovedUsdtReversal($customer->id, UsdtMicroAmount::fromUnits(5_000_000), $credit->entryId, 'approval-2'), 'usdt:reversal-2');
            $this->fail('Expected a second reversal to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_non_positive_sources_and_empty_idempotency_keys_are_rejected(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902110]);
        $ledger = $this->ledger();

        foreach (['', '   ', str_repeat('k', 192)] as $invalidKey) {
            try {
                $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), $invalidKey);
                $this->fail("Expected idempotency key [{$invalidKey}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CustomerUsdtBalanceEntry::query()->count());
    }

    public function test_ledger_entries_are_immutable_and_the_projection_is_not_mass_assignable(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902111]);
        $this->ledger()->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:immutable');

        $entry = CustomerUsdtBalanceEntry::query()->sole();
        try {
            $entry->forceFill(['amount_micro' => 1])->save();
            $this->fail('Expected ledger entries to be immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $entry->delete();
            $this->fail('Expected ledger entries to be immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $account = CustomerUsdtBalanceAccount::query()->sole();
        $account->fill(['balance_micro' => 999_999_999]);

        $this->assertFalse($account->isDirty('balance_micro'));
        $this->assertSame(5_000_000, $this->ledger()->snapshot($customer)->balanceMicro());
    }

    public function test_snapshot_fails_safe_on_projection_or_cursor_drift_and_mutations_freeze(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902112]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:drift');
        $account = CustomerUsdtBalanceAccount::query()->sole();

        DB::table('customer_usdt_balance_accounts')->where('id', $account->id)->update(['balance_micro' => 5_000_001]);

        $snapshot = $ledger->snapshot($customer);
        $this->assertFalse($snapshot->isAvailable());
        $this->assertSame(0, $snapshot->balanceMicro());

        try {
            $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 1_000_000, 'topup-2'), 'usdt:drift-2');
            $this->fail('Expected a drifted projection to freeze mutations.');
        } catch (UsdtBalanceProjectionMismatchException $exception) {
            $this->assertSame($customer->id, $exception->customerId);
            $this->assertSame(5_000_001, $exception->projectedMicro);
        }

        $replayOnDrift = $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:drift');
        $this->assertFalse($replayOnDrift->applied);
        $this->assertSame(1, $replayOnDrift->entryId);
        $this->assertSame(1, DB::table('customer_usdt_balance_accounting_events')->count());

        DB::table('customer_usdt_balance_accounts')->where('id', $account->id)->update(['balance_micro' => 5_000_000, 'version' => 7]);
        $this->assertFalse($ledger->snapshot($customer)->isAvailable());

        try {
            $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 1_000_000, 'topup-3'), 'usdt:drift-3');
            $this->fail('Expected a drifted cursor to freeze mutations.');
        } catch (UsdtBalanceProjectionMismatchException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_accounting_events')->count());
    }

    public function test_frozen_accounts_reject_mutations_without_money_movement(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902113]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:freeze');
        CustomerUsdtBalanceAccount::query()->sole()->forceFill(['frozen_reason' => 'reorg review'])->save();

        $snapshot = $ledger->snapshot($customer);
        $this->assertTrue($snapshot->isAvailable());
        $this->assertTrue($snapshot->isFrozen());
        $this->assertSame('reorg review', $snapshot->frozenReason());

        $replay = $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:freeze');
        $this->assertFalse($replay->applied);
        $this->assertSame(1, $replay->entryId);

        try {
            $ledger->debitPurchase($customer, new UsdtPurchase($customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'order-1', 'payment-1'), 'order:order-1:debit');
            $this->fail('Expected the frozen account to reject the debit.');
        } catch (UsdtBalanceFrozenException $exception) {
            $this->assertSame($customer->id, $exception->customerId);
            $this->assertSame('reorg review', $exception->reason);
        }

        try {
            DB::transaction(fn () => $ledger->lockForTransaction($customer));
            $this->fail('Expected lockForTransaction to refuse a frozen account.');
        } catch (UsdtBalanceFrozenException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(5_000_000, CustomerUsdtBalanceAccount::query()->sole()->balance_micro);
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_accounting_events')->count());
    }

    public function test_integer_overflow_rolls_back_without_partial_entry_projection_or_accounting(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902114]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, PHP_INT_MAX, 'topup-max'), 'usdt:max');

        try {
            $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id, 1, 'topup-overflow'), 'usdt:overflow');
            $this->fail('Expected the overflow credit to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(PHP_INT_MAX, CustomerUsdtBalanceAccount::query()->sole()->balance_micro);
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_accounting_events')->count());
    }

    public function test_accounting_failure_rolls_back_the_entry_and_projection_atomically(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902120]);
        $ledger = $this->ledger();
        $credit = $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:accounting-rollback');

        // Force the posting to fail after its entry and projection update: the
        // reversal cannot find the original balanced counter-leg, so the whole
        // transaction must roll back with no partial ledger row.
        DB::table('customer_usdt_balance_accounting_events')->delete();

        try {
            $ledger->reverseApprovedEntry($customer, new ApprovedUsdtReversal($customer->id, UsdtMicroAmount::fromUnits(5_000_000), $credit->entryId, 'approval-rollback'), 'usdt:accounting-rollback-reversal');
            $this->fail('Expected the missing original accounting event to reject the reversal.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(5_000_000, $ledger->snapshot($customer)->balanceMicro());
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_accounting_events')->count());
        $account = CustomerUsdtBalanceAccount::query()->sole();
        $this->assertSame(1, $account->version);
        $this->assertSame(1, $account->last_entry_id);
    }

    public function test_accounting_events_accept_exactly_one_append_per_entry(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902115]);
        $ledger = $this->ledger();
        $ledger->creditVerifiedTopUp($customer, $this->funding($customer->id), 'usdt:event');
        $event = DB::table('customer_usdt_balance_accounting_events')->sole();

        try {
            DB::table('customer_usdt_balance_accounting_events')->insert([
                'customer_usdt_balance_entry_id' => $event->customer_usdt_balance_entry_id,
                'currency' => 'USDT',
                'amount_micro' => 1,
                'debit_account' => 'usdt_settlement_asset',
                'credit_account' => 'customer_usdt_liability',
                'source_fingerprint' => str_repeat('a', 64),
                'created_at' => now(),
            ]);
            $this->fail('Expected a duplicate accounting event to be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DB::table('customer_usdt_balance_accounting_events')->count());
    }

    public function test_lock_for_transaction_creates_the_account_without_moving_money(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902116]);

        $account = DB::transaction(fn () => $this->ledger()->lockForTransaction($customer));

        $this->assertSame($customer->id, $account->customer_id);
        $this->assertSame(0, $account->balance_micro);
        $this->assertSame(0, $account->version);
        $this->assertSame(1, CustomerUsdtBalanceAccount::query()->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::query()->count());
        $this->assertTrue($this->ledger()->snapshot($customer)->isAvailable());
    }

    public function test_idr_store_balance_and_usdt_ledgers_stay_fully_separate(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902117]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            25_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            'idr-separation',
            'balance_top_up:idr-separation:credit',
        );
        $this->ledger()->creditVerifiedTopUp($customer, $this->funding($customer->id, 5_000_000, 'topup-separation-1'), 'usdt:separation');

        $this->assertSame(25_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(5_000_000, $this->ledger()->snapshot($customer)->balanceMicro());
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, CustomerBalanceAccount::query()->count());
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(1, CustomerUsdtBalanceAccount::query()->count());

        $usdtOnly = Customer::factory()->create(['telegram_id' => 902118]);
        $this->ledger()->creditVerifiedTopUp($usdtOnly, $this->funding($usdtOnly->id, 5_000_000, 'topup-separation-2'), 'usdt:only');
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, CustomerBalanceAccount::query()->count());

        $idrOnly = Customer::factory()->create(['telegram_id' => 902119]);
        try {
            app(StoreBalanceLedger::class)->debit($idrOnly, 1, 'order', 'order-x', 'order:order-x:debit');
            $this->fail('Expected the IDR ledger to reject the debit without funds.');
        } catch (InsufficientStoreBalance) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(2, CustomerUsdtBalanceAccount::query()->count());
        $this->assertSame(2, CustomerUsdtBalanceEntry::query()->count());
    }
}
