<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceEntryKind;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\StoreBalanceEntry;
use App\Services\StoreBalance\StoreBalanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class StoreBalanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_appends_one_immutable_entry_and_moves_the_projection_atomically(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901101]);

        $result = app(StoreBalanceLedger::class)->credit(
            $customer,
            25000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '88',
            'balance_top_up:88:credit',
        );

        $this->assertTrue($result->applied);
        $this->assertSame(25000, $result->amount);
        $this->assertSame(25000, $result->balanceAfter);
        $this->assertSame(StoreBalanceEntryKind::TopUpCredit, $result->kind);

        $account = CustomerBalanceAccount::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(25000, $account->balance);

        $entry = StoreBalanceEntry::query()->sole();
        $this->assertSame($account->id, $entry->customer_balance_account_id);
        $this->assertSame($result->entryId, $entry->id);
        $this->assertSame(StoreBalanceEntryKind::TopUpCredit, $entry->kind);
        $this->assertSame(25000, $entry->amount);
        $this->assertSame(25000, $entry->balance_after);
        $this->assertSame('balance_top_up', $entry->source_type);
        $this->assertSame('88', $entry->source_id);
        $this->assertSame('balance_top_up:88:credit', $entry->idempotency_key);
        $this->assertNotNull($entry->created_at);
    }

    public function test_credit_rejects_integer_overflow_without_mutating_balance(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901117]);
        $ledger = app(StoreBalanceLedger::class);

        $ledger->credit(
            $customer,
            PHP_INT_MAX,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            'overflow-1',
            'balance_top_up:overflow-1:credit',
        );

        try {
            $ledger->credit(
                $customer,
                1,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                'overflow-2',
                'balance_top_up:overflow-2:credit',
            );
            $this->fail('An overflowing Store Balance credit must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('integer range', $exception->getMessage());
        }

        $this->assertSame(PHP_INT_MAX, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(1, StoreBalanceEntry::query()->count());
    }

    public function test_reapplying_the_same_idempotency_key_returns_the_existing_result_without_a_second_entry(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901102]);
        $ledger = app(StoreBalanceLedger::class);

        $first = $ledger->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '1', 'balance_top_up:1:credit');
        $replayed = $ledger->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '1', 'balance_top_up:1:credit');

        $this->assertTrue($first->applied);
        $this->assertFalse($replayed->applied);
        $this->assertSame($first->entryId, $replayed->entryId);
        $this->assertSame(10000, $replayed->balanceAfter);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(10000, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_reapplying_the_same_source_identity_with_a_new_key_replays_the_economic_result(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901103]);
        $ledger = app(StoreBalanceLedger::class);

        $ledger->credit($customer, 15000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '701', 'balance_top_up:701:credit');
        $ledger->debit($customer, 15000, 'order', '701', 'order:701:debit');
        $first = $ledger->refund($customer, 15000, 'order', '701', 'order:701:refund:attempt-1');
        $replayed = $ledger->refund($customer, 15000, 'order', '701', 'order:701:refund:attempt-2');

        $this->assertTrue($first->applied);
        $this->assertFalse($replayed->applied);
        $this->assertSame($first->entryId, $replayed->entryId);
        $this->assertSame(3, StoreBalanceEntry::query()->count());
        $this->assertSame(15000, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_generic_credit_cannot_bypass_the_canonical_refund_operation(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901115]);

        $this->expectException(InvalidArgumentException::class);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            15000,
            StoreBalanceEntryKind::RefundCredit,
            'order',
            '702',
            'order:702:refund',
        );
    }

    public function test_reusing_a_claim_with_different_amount_or_source_is_rejected(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901113]);
        $ledger = app(StoreBalanceLedger::class);

        $ledger->credit(
            $customer,
            10000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            'claim-1',
            'shared-claim',
        );

        foreach ([
            [12000, 'balance_top_up', 'claim-2'],
            [10000, 'other_source', 'claim-1'],
        ] as [$amount, $sourceType, $sourceId]) {
            try {
                $ledger->credit(
                    $customer,
                    $amount,
                    StoreBalanceEntryKind::TopUpCredit,
                    $sourceType,
                    $sourceId,
                    'shared-claim',
                );
                $this->fail('A claimed Store Balance identity must reject conflicting values.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(10000, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_the_same_idempotency_key_is_scoped_to_one_customer(): void
    {
        $first = Customer::factory()->create(['telegram_id' => 901104]);
        $second = Customer::factory()->create(['telegram_id' => 901105]);
        $ledger = app(StoreBalanceLedger::class);

        $firstResult = $ledger->credit($first, 5000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '11', 'shared-claim');
        $secondResult = $ledger->credit($second, 5000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '12', 'shared-claim');

        $this->assertTrue($firstResult->applied);
        $this->assertTrue($secondResult->applied);
        $this->assertSame(2, StoreBalanceEntry::query()->count());
        $this->assertSame(5000, CustomerBalanceAccount::query()->where('customer_id', $first->id)->value('balance'));
        $this->assertSame(5000, CustomerBalanceAccount::query()->where('customer_id', $second->id)->value('balance'));
    }

    public function test_debit_reduces_the_projection_and_records_the_resulting_balance(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901106]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 30000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '2', 'balance_top_up:2:credit');

        $debit = $ledger->debit($customer, 30000, 'order', '900', 'order:900:debit');
        $replayed = $ledger->debit($customer, 30000, 'order', '900', 'order:900:debit');

        $this->assertTrue($debit->applied);
        $this->assertSame(StoreBalanceEntryKind::PurchaseDebit, $debit->kind);
        $this->assertSame(0, $debit->balanceAfter);
        $this->assertFalse($replayed->applied);
        $this->assertSame($debit->entryId, $replayed->entryId);
        $this->assertSame(0, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(2, StoreBalanceEntry::query()->count());
    }

    public function test_insufficient_debit_changes_no_money_and_creates_no_account(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901107]);

        try {
            app(StoreBalanceLedger::class)->debit($customer, 5000, 'order', '901', 'order:901:debit');
            $this->fail('Expected InsufficientStoreBalance.');
        } catch (InsufficientStoreBalance) {
            // Expected.
        }

        $this->assertSame(0, CustomerBalanceAccount::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_debit_replay_stays_idempotent_after_the_balance_is_spent(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901108]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '3', 'balance_top_up:3:credit');

        $ledger->debit($customer, 10000, 'order', '902', 'order:902:debit');
        $replayed = $ledger->debit($customer, 10000, 'order', '902', 'order:902:debit');

        $this->assertFalse($replayed->applied);
        $this->assertSame(0, $replayed->balanceAfter);
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
    }

    public function test_ledger_rejects_non_positive_amounts_and_empty_identities(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901109]);
        $ledger = app(StoreBalanceLedger::class);

        foreach ([0, -1] as $amount) {
            try {
                $ledger->credit($customer, $amount, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '4', 'balance_top_up:4:credit');
                $this->fail('Expected an invalid amount rejection.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        foreach ([
            ['', '4', 'key'],
            ['balance_top_up', '', 'key'],
            ['balance_top_up', '4', ''],
            ['balance_top_up', '4', str_repeat('k', 192)],
        ] as [$sourceType, $sourceId, $key]) {
            try {
                $ledger->credit($customer, 1000, StoreBalanceEntryKind::TopUpCredit, $sourceType, $sourceId, $key);
                $this->fail('Expected an invalid identity rejection.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, CustomerBalanceAccount::query()->count());
    }

    public function test_ledger_entries_and_direct_balance_assignment_are_immutable(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901110]);
        app(StoreBalanceLedger::class)->credit($customer, 12000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '5', 'balance_top_up:5:credit');

        $entry = StoreBalanceEntry::query()->sole();
        $account = CustomerBalanceAccount::query()->where('customer_id', $customer->id)->sole();

        try {
            $entry->update(['amount' => 1]);
            $this->fail('Ledger entries must reject updates.');
        } catch (LogicException) {
            // Expected.
        }

        try {
            $entry->delete();
            $this->fail('Ledger entries must reject deletes.');
        } catch (LogicException) {
            // Expected.
        }

        $account->fill(['balance' => 999999]);
        $this->assertFalse($account->isDirty('balance'));
        $this->assertSame(12000, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(12000, StoreBalanceEntry::query()->sole()->amount);
    }

    public function test_snapshot_reads_the_projection_fresh_and_fails_safe_on_drift(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901111]);
        $ledger = app(StoreBalanceLedger::class);

        $empty = $ledger->snapshot($customer);
        $this->assertTrue($empty->isAvailable());
        $this->assertSame(0, $empty->balance());

        $ledger->credit($customer, 8000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '6', 'balance_top_up:6:credit');
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());

        $ledger->credit($customer, 2000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '7', 'balance_top_up:7:credit');
        $this->assertSame(10000, $ledger->snapshot($customer)->balance());

        CustomerBalanceAccount::query()
            ->where('customer_id', $customer->id)
            ->update(['balance' => 999999]);

        $drifted = $ledger->snapshot($customer);
        $this->assertFalse($drifted->isAvailable());
        $this->assertSame(0, $drifted->balance());
        try {
            $ledger->credit($customer, 1000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '8', 'balance_top_up:8:credit');
            $this->fail('A projection mismatch must block balance mutation.');
        } catch (StoreBalanceProjectionMismatchException $exception) {
            $this->assertSame($customer->id, $exception->customerId);
            $this->assertSame(999999, $exception->projectedBalance);
            $this->assertSame(10000, $exception->ledgerBalance);
        }

        $this->assertSame(2, StoreBalanceEntry::query()->count());
        $this->assertSame(999999, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_refund_requires_a_matching_order_debit(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901114]);
        $ledger = app(StoreBalanceLedger::class);

        $ledger->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '9', 'balance_top_up:9:credit');
        $ledger->debit($customer, 10000, 'order', '903', 'manual-debit-key');

        try {
            $ledger->refund($customer, 9000, 'order', '903', 'order:903:refund');
            $this->fail('A refund must require the exact original debit amount.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(2, StoreBalanceEntry::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
    }

    public function test_account_with_an_untracked_balance_and_no_entries_is_unavailable(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 901112]);
        CustomerBalanceAccount::query()->create(['customer_id' => $customer->id]);
        CustomerBalanceAccount::query()->where('customer_id', $customer->id)->update(['balance' => 50000]);

        $snapshot = app(StoreBalanceLedger::class)->snapshot($customer);

        $this->assertFalse($snapshot->isAvailable());
    }
}
