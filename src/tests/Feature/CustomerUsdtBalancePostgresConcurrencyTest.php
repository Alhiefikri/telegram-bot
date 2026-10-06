<?php

namespace Tests\Feature;

use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\InsufficientUsdtBalance;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\Order;
use App\Services\UsdtBalance\ApprovedUsdtReversal;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtBalance\UsdtRefund;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class CustomerUsdtBalancePostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    /** @var list<int> */
    private array $createdCustomerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('customer_usdt_balance_entries')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function test_postgres_parallel_credits_keep_every_entry_projection_and_accounting_exact(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906101);
        $idrEntries = (int) DB::table('store_balance_entries')->count();
        $idrAccounts = (int) DB::table('customer_balance_accounts')->count();

        $results = $this->runConcurrentWorkers(4, function (int $index) use ($customer): string {
            $result = app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
                Customer::query()->findOrFail($customer->id),
                new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(100_000), 'topup-race-'.$index, 'receipt-'.$index, 'v1'),
                'usdt:credit-race-'.$index,
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['Applied', 'Applied', 'Applied', 'Applied'], array_map(
            static fn (array $result): string => (string) ($result['value'] ?? ''),
            $results,
        ));

        $this->assertSame(4, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(4, DB::table('customer_usdt_balance_accounting_events')->count());
        $this->assertSame([1, 2, 3, 4], CustomerUsdtBalanceEntry::query()->orderBy('id')->pluck('account_sequence')->map(fn ($value): int => (int) $value)->all());
        $this->assertSame(400_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 400_000);

        $this->assertSame($idrEntries, (int) DB::table('store_balance_entries')->count());
        $this->assertSame($idrAccounts, (int) DB::table('customer_balance_accounts')->count());
    }

    public function test_postgres_competing_debits_never_commit_a_negative_balance(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906102);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(25_000), 'topup-debit-race', 'receipt-debit-race', 'v1'),
            'usdt:debit-race-credit',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            $result = app(CustomerUsdtBalanceLedger::class)->debitPurchase(
                Customer::query()->findOrFail($customer->id),
                new UsdtPurchase((int) $customer->id, UsdtMicroAmount::fromUnits(20_000), 'order-debit-race-'.$index, 'payment-'.$index),
                'order:debit-race-'.$index,
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertCount(1, array_filter($results, static fn (array $result): bool => ($result['value'] ?? null) === 'Applied'), json_encode($results, JSON_THROW_ON_ERROR));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InsufficientUsdtBalance::class, $failures[0]['class'] ?? null);

        $this->assertSame(5_000, $this->balanceOf($customer));
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(2, CustomerUsdtBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 5_000);
    }

    public function test_postgres_empty_account_creation_race_is_safe(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906103);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            $result = app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
                Customer::query()->findOrFail($customer->id),
                new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(10_000), 'topup-init-race-'.$index, 'receipt-init-race-'.$index, 'v1'),
                'usdt:init-race-'.$index,
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(20_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 20_000);
    }

    public function test_postgres_competing_refunds_cannot_exceed_the_original_debit(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906104);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $ledger = app(CustomerUsdtBalanceLedger::class);
        $ledger->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(5_000_000), 'topup-refund-race', 'receipt-refund-race', 'v1'),
            'usdt:refund-race-credit',
        );
        $debit = $ledger->debitPurchase(
            $customer,
            new UsdtPurchase((int) $customer->id, UsdtMicroAmount::fromUnits(2_500_000), (string) $order->id, 'payment-refund-race'),
            'order:refund-race-debit',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer, $order, $debit): string {
            $result = app(CustomerUsdtBalanceLedger::class)->refundEntitlement(
                Customer::query()->findOrFail($customer->id),
                new UsdtRefund((int) $customer->id, UsdtMicroAmount::fromUnits(2_000_000), 'refund-race-'.$index, (string) $order->id, $debit->entryId),
                'order-refund:refund-race-'.$index,
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertCount(1, array_filter($results, static fn (array $result): bool => ($result['value'] ?? null) === 'Applied'), json_encode($results, JSON_THROW_ON_ERROR));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InvalidArgumentException::class, $failures[0]['class'] ?? null);

        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->where('kind', UsdtBalanceEntryKind::RefundCredit)->count());
        $this->assertSame(4_500_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 4_500_000);
    }

    public function test_postgres_reversal_and_spend_race_keeps_one_economic_effect(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906105);
        $credit = app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(5_000_000), 'topup-reversal-race', 'receipt-reversal-race', 'v1'),
            'usdt:reversal-race-credit',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer, $credit): string {
            if ($index === 0) {
                $result = app(CustomerUsdtBalanceLedger::class)->reverseApprovedEntry(
                    Customer::query()->findOrFail($customer->id),
                    new ApprovedUsdtReversal((int) $customer->id, UsdtMicroAmount::fromUnits(5_000_000), $credit->entryId, 'approval-reversal-race'),
                    'usdt:reversal-race',
                );

                return $result->applied ? 'Reversed' : 'Replayed';
            }

            $result = app(CustomerUsdtBalanceLedger::class)->debitPurchase(
                Customer::query()->findOrFail($customer->id),
                new UsdtPurchase((int) $customer->id, UsdtMicroAmount::fromUnits(3_000_000), 'order-spend-race', 'payment-spend-race'),
                'order:spend-race',
            );

            return $result->applied ? 'Spent' : 'Replayed';
        });

        $this->assertCount(1, array_filter($results, static fn (array $result): bool => in_array($result['value'] ?? null, ['Reversed', 'Spent'], true)), json_encode($results, JSON_THROW_ON_ERROR));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InsufficientUsdtBalance::class, $failures[0]['class'] ?? null);

        $balance = $this->balanceOf($customer);
        $this->assertContains($balance, [0, 2_000_000]);
        $this->assertProjectionMatchesLedger($customer, $balance);
    }

    public function test_postgres_constraints_reject_negative_balance_zero_amount_and_duplicate_claims(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906106);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'topup-constraints', 'receipt-constraints', 'v1'),
            'usdt:constraints-credit',
        );
        $accountId = (int) CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->value('id');
        $entry = CustomerUsdtBalanceEntry::query()->sole();

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_balance_accounts')->where('id', $accountId)->update(['balance_micro' => -1]),
            'customer_usdt_balance_accounts_balance_nonnegative',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_balance_accounts')->where('id', $accountId)->update(['currency' => 'IDR']),
            'customer_usdt_balance_accounts_currency_check',
        );

        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_balance_entries')->insert([
            'customer_usdt_balance_account_id' => $accountId,
            'account_sequence' => 2,
            'kind' => UsdtBalanceEntryKind::FundingCredit->value,
            'amount_micro' => 1_000_000,
            'balance_after_micro' => 2_000_000,
            'source_type' => 'customer_usdt_top_up',
            'source_id' => 'topup-constraints',
            'idempotency_key' => 'usdt:duplicate-source',
            'request_fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
        ]), 'usdt_balance_entries_source_unique');

        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_balance_entries')->insert([
            'customer_usdt_balance_account_id' => $accountId,
            'account_sequence' => 2,
            'kind' => 'wallet_row',
            'amount_micro' => 1_000_000,
            'balance_after_micro' => 2_000_000,
            'source_type' => 'order',
            'source_id' => 'order-invalid-kind',
            'idempotency_key' => 'order:invalid-kind',
            'request_fingerprint' => str_repeat('e', 64),
            'created_at' => now(),
        ]), 'customer_usdt_balance_entries_kind_check');

        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_balance_entries')->insert([
            'customer_usdt_balance_account_id' => $accountId,
            'account_sequence' => 2,
            'kind' => UsdtBalanceEntryKind::PurchaseDebit->value,
            'amount_micro' => 0,
            'balance_after_micro' => 1_000_000,
            'source_type' => 'order',
            'source_id' => 'order-zero',
            'idempotency_key' => 'order:zero',
            'request_fingerprint' => str_repeat('b', 64),
            'created_at' => now(),
        ]), 'customer_usdt_balance_entries_amount_positive');

        $this->assertDatabaseConstraintRejects(fn () => DB::table('customer_usdt_balance_accounting_events')->insert([
            'customer_usdt_balance_entry_id' => $entry->id,
            'currency' => 'IDR',
            'amount_micro' => 1,
            'debit_account' => 'usdt_settlement_asset',
            'credit_account' => 'customer_usdt_liability',
            'source_fingerprint' => str_repeat('c', 64),
            'created_at' => now(),
        ]), 'customer_usdt_balance_accounting_events_currency_check');

        $this->assertSame(1_000_000, $this->balanceOf($customer));
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 1_000_000);
    }

    public function test_postgres_forbids_entry_and_accounting_edits_at_runtime(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906107);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'topup-immutable', 'receipt-immutable', 'v1'),
            'usdt:immutable-credit',
        );
        $entryId = (int) CustomerUsdtBalanceEntry::query()->value('id');
        $eventId = (int) DB::table('customer_usdt_balance_accounting_events')->value('id');

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_balance_entries')->where('id', $entryId)->update(['amount_micro' => 1]),
            'immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_balance_entries')->where('id', $entryId)->delete(),
            'immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_balance_accounting_events')->where('id', $eventId)->update(['amount_micro' => 1]),
            'immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_balance_accounting_events')->where('id', $eventId)->delete(),
            'immutable',
        );

        $this->assertSame(1_000_000, $this->balanceOf($customer));
    }

    public function test_postgres_runtime_role_can_append_but_not_mutate_the_ledger(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906108);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'topup-role', 'receipt-role', 'v1'),
            'usdt:role-credit',
        );
        $accountId = (int) CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->value('id');
        $entryId = (int) CustomerUsdtBalanceEntry::query()->value('id');
        $eventId = (int) DB::table('customer_usdt_balance_accounting_events')->value('id');

        $canCreateRole = (bool) DB::selectOne(
            'SELECT (rolsuper OR rolcreaterole) AS allowed FROM pg_roles WHERE rolname = current_user',
        )->allowed;

        if (! $canCreateRole) {
            // Without role-management rights the trigger remains the runtime
            // denial boundary, which is asserted for the current role.
            $this->assertDatabaseConstraintRejects(
                fn () => DB::table('customer_usdt_balance_entries')->where('id', $entryId)->update(['amount_micro' => 1]),
                'immutable',
            );

            return;
        }

        $role = 'usdt_ledger_runtime_'.bin2hex(random_bytes(4));

        try {
            DB::statement("CREATE ROLE {$role} NOLOGIN");
            DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
            DB::statement("GRANT SELECT, INSERT ON customer_usdt_balance_accounts, customer_usdt_balance_entries, customer_usdt_balance_accounting_events TO {$role}");
            DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");

            DB::statement("SET ROLE {$role}");

            DB::table('customer_usdt_balance_entries')->insert([
                'customer_usdt_balance_account_id' => $accountId,
                'account_sequence' => 2,
                'kind' => UsdtBalanceEntryKind::FundingCredit->value,
                'amount_micro' => 1,
                'balance_after_micro' => 1_000_001,
                'source_type' => 'customer_usdt_top_up',
                'source_id' => 'topup-role-append',
                'idempotency_key' => 'usdt:role-append',
                'request_fingerprint' => str_repeat('d', 64),
                'created_at' => now(),
            ]);

            $this->assertDatabaseConstraintRejects(
                fn () => DB::table('customer_usdt_balance_entries')->where('id', $entryId)->update(['amount_micro' => 1]),
                'permission denied',
            );
            $this->assertDatabaseConstraintRejects(
                fn () => DB::table('customer_usdt_balance_entries')->where('id', $entryId)->delete(),
                'permission denied',
            );
            $this->assertDatabaseConstraintRejects(
                fn () => DB::table('customer_usdt_balance_accounting_events')->where('id', $eventId)->update(['amount_micro' => 1]),
                'permission denied',
            );
        } finally {
            DB::statement('RESET ROLE');
            DB::statement("DROP OWNED BY {$role}");
            DB::statement("DROP ROLE {$role}");
        }

        $this->assertSame(2, CustomerUsdtBalanceEntry::query()->count());
    }

    public function test_postgres_readers_never_observe_a_ledger_projection_divergence(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(906109);
        $directory = $this->createWorkspace();
        $children = [];

        try {
            DB::disconnect();
            $children[] = $this->forkWorker($directory, 0, function () use ($customer, $directory): string {
                for ($index = 1; $index <= 15; $index++) {
                    app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
                        Customer::query()->findOrFail($customer->id),
                        new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000), 'topup-reader-'.$index, 'receipt-reader-'.$index, 'v1'),
                        'usdt:reader-'.$index,
                    );
                }

                touch($directory.'/writer-done');

                return 'Credited';
            });

            $children[] = $this->forkWorker($directory, 1, function () use ($customer, $directory): string {
                $samples = 0;
                $divergences = 0;
                $deadline = microtime(true) + 20;

                while (! is_file($directory.'/writer-done') && microtime(true) < $deadline) {
                    $snapshot = app(CustomerUsdtBalanceLedger::class)->snapshot(Customer::query()->findOrFail($customer->id));
                    if (! $snapshot->isAvailable()) {
                        $divergences++;
                    }
                    $samples++;
                    usleep(2_000);
                }

                return json_encode(['samples' => $samples, 'divergences' => $divergences], JSON_THROW_ON_ERROR);
            });

            foreach ([0, 1] as $index) {
                $this->waitForMarker($directory.'/ready-'.$index);
            }
            touch($directory.'/start');

            $results = $this->collectResults($children, $directory);
        } finally {
            $this->cleanupWorkspace($directory);
            DB::reconnect();
        }

        $this->assertWorkersSucceeded($results);
        $reader = json_decode((string) ($results[1]['value'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(3, $reader['samples']);
        $this->assertSame(0, $reader['divergences']);
        $this->assertSame(15_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 15_000);
    }

    private function cleanupFixtures(): void
    {
        if ($this->createdCustomerIds === [] || DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Truncate bypasses the row-level immutability triggers, which is the
        // only supported way to remove an append-only ledger after a test.
        DB::statement('TRUNCATE customer_usdt_balance_accounting_events, customer_usdt_balance_entries, customer_usdt_balance_accounts RESTART IDENTITY CASCADE');
        Order::query()->whereIn('customer_id', $this->createdCustomerIds)->delete();
        Customer::query()->whereIn('id', $this->createdCustomerIds)->delete();
        $this->createdCustomerIds = [];
    }

    private function createCustomer(int $telegramId): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $this->createdCustomerIds[] = (int) $customer->id;

        return $customer;
    }

    private function balanceOf(Customer $customer): int
    {
        return app(CustomerUsdtBalanceLedger::class)->snapshot(Customer::query()->findOrFail($customer->id))->balanceMicro();
    }

    private function assertProjectionMatchesLedger(Customer $customer, int $expected): void
    {
        $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole();
        $debits = UsdtBalanceEntryKind::debitValues();
        $placeholders = implode(', ', array_fill(0, count($debits), '?'));
        $ledgerBalance = (int) CustomerUsdtBalanceEntry::query()
            ->where('customer_usdt_balance_account_id', $account->id)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN kind IN ({$placeholders}) THEN -amount_micro ELSE amount_micro END), 0) AS ledger_balance",
                $debits,
            )
            ->value('ledger_balance');

        $snapshot = app(CustomerUsdtBalanceLedger::class)->snapshot($customer);
        $this->assertTrue($snapshot->isAvailable());
        $this->assertSame($expected, (int) $account->balance_micro);
        $this->assertSame($expected, $ledgerBalance);
        $this->assertSame($expected, $snapshot->balanceMicro());
    }
}
