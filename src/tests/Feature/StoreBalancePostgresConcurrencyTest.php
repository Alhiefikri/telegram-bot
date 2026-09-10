<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\StoreBalanceFundingPolicyConflict;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingPolicy;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceReconciliation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
#[Group('postgres-release')]
final class StoreBalancePostgresConcurrencyTest extends TestCase
{
    /** @var list<int> */
    private array $createdCustomerIds = [];

    /** @var list<int> */
    private array $createdProductIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('store_balance_entries')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdCustomerIds !== [] && Schema::hasTable('store_balance_entries')) {
            Customer::query()->whereKey($this->createdCustomerIds)->delete();
        }
        if ($this->createdProductIds !== [] && Schema::hasTable('products')) {
            Product::query()->whereKey($this->createdProductIds)->delete();
        }

        parent::tearDown();
    }

    public function test_postgres_competing_policy_updates_allow_one_stale_version_winner(): void
    {
        $this->requirePostgresConcurrency();

        $owners = User::factory()->count(2)->create(['role' => UserRole::Owner]);
        $ownerIds = $owners->modelKeys();

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($ownerIds): string {
            try {
                app(StoreBalanceFundingPolicyManager::class)->change(
                    minimumAmount: '5000',
                    presetAmounts: $index === 0 ? [5000, 10000] : [5000, 25000],
                    expectedVersion: 0,
                    actor: User::query()->findOrFail($ownerIds[$index]),
                    ip: '198.51.100.'.($index + 10),
                );

                return 'Applied';
            } catch (StoreBalanceFundingPolicyConflict) {
                return 'Conflict';
            }
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Conflict'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $policy = StoreBalanceFundingPolicy::query()->sole();
        $this->assertSame(1, $policy->policyVersion());
        $this->assertContains($policy->presetAmounts(), [[5000, 10000], [5000, 25000]]);
        $this->assertSame(1, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    public function test_postgres_workers_settle_one_idempotency_claim_exactly_once(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45401);

        $results = $this->runConcurrentWorkers(2, function () use ($customer): string {
            $result = app(StoreBalanceLedger::class)->credit(
                Customer::query()->findOrFail($customer->id),
                25000,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                '7001',
                'balance_top_up:7001:credit',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(25000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 25000);
    }

    public function test_postgres_competing_debits_never_commit_a_negative_balance(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45402);
        app(StoreBalanceLedger::class)->credit($customer, 25000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '7002', 'balance_top_up:7002:credit');

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            $result = app(StoreBalanceLedger::class)->debit(
                Customer::query()->findOrFail($customer->id),
                20000,
                'order',
                '800'.$index,
                'order:800'.$index.':debit',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertCount(1, array_filter($results, static fn (array $result): bool => ($result['value'] ?? null) === 'Applied'), json_encode($results, JSON_THROW_ON_ERROR));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InsufficientStoreBalance::class, $failures[0]['class'] ?? null);
        $this->assertSame(1, $failures[0]['exit_code'] ?? null);

        $this->assertSame(5000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(2, StoreBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 5000);
    }

    public function test_postgres_competing_store_balance_checkouts_commit_one_order_without_overspending(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45408);
        $cartIds = [];
        for ($index = 0; $index < 2; $index++) {
            $product = Product::factory()->create([
                'price_amount' => 20_000,
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);
            $this->createdProductIds[] = $product->id;
            $cart = Cart::factory()->create([
                'customer_id' => $customer->id,
                'status' => CartStatus::Active,
                'expires_at' => now()->addHour(),
            ]);
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
            $cartIds[] = $cart->id;
        }
        app(StoreBalanceLedger::class)->credit(
            $customer,
            25_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45408',
            'balance_top_up:45408:credit',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($cartIds): string {
            $order = app(OrderCheckoutService::class)->checkout(
                Cart::query()->findOrFail($cartIds[$index]),
                CheckoutPaymentIntents::storeBalance(),
                'postgres-balance-checkout:'.$index,
            );

            return $order->payment_status->value;
        });

        $successes = array_values(array_filter($results, static fn (array $result): bool => ($result['value'] ?? null) === PaymentStatus::Paid->value));
        $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === false));
        $this->assertCount(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(InsufficientStoreBalance::class, $failures[0]['class'] ?? null);
        $this->assertSame(1, Order::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(5_000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 5_000);
    }

    public function test_postgres_same_checkout_key_reuses_one_paid_order_and_one_debit(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45409);
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->createdProductIds[] = $product->id;
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45409',
            'balance_top_up:45409:credit',
        );

        $results = $this->runConcurrentWorkers(2, function () use ($cart): string {
            $order = app(OrderCheckoutService::class)->checkout(
                Cart::query()->findOrFail($cart->id),
                CheckoutPaymentIntents::storeBalance(),
                'postgres-balance-same-intent',
            );

            return $order->payment_status->value;
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame([PaymentStatus::Paid->value, PaymentStatus::Paid->value], array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results));
        $this->assertSame(1, Order::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(0, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 0);
    }

    public function test_postgres_concurrent_store_balance_refunds_credit_once_through_lifecycle(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45410);
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->createdProductIds[] = $product->id;
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45410',
            'balance_top_up:45410:credit',
        );

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::storeBalance(),
            'postgres-balance-refund',
        );
        $paymentId = $order->payments()->sole()->id;
        $results = $this->runConcurrentWorkers(2, function () use ($paymentId): string {
            $result = app(OrderStateMachine::class)->applyPaymentRefunded(
                Payment::query()->findOrFail($paymentId),
            );

            return $result->newlyApplied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(PaymentStatus::Refunded->value, $order->fresh()->payment_status->value);
        $this->assertSame(20_000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertProjectionMatchesLedger($customer, 20_000);
    }

    public function test_postgres_store_balance_refund_serializes_with_another_account_mutation(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45411);
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->createdProductIds[] = $product->id;
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'status' => CartStatus::Active,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '45411',
            'balance_top_up:45411:credit',
        );

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::storeBalance(),
            'postgres-balance-refund-mutation',
        );
        $paymentId = $order->payments()->sole()->id;

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer, $paymentId): string {
            if ($index === 0) {
                $result = app(OrderStateMachine::class)->applyPaymentRefunded(
                    Payment::query()->findOrFail($paymentId),
                );

                return $result->newlyApplied ? 'RefundApplied' : 'RefundReplayed';
            }

            $result = app(StoreBalanceLedger::class)->credit(
                Customer::query()->findOrFail($customer->id),
                5_000,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                '45411-race',
                'balance_top_up:45411-race:credit',
            );

            return $result->applied ? 'BalanceApplied' : 'BalanceReplayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['BalanceApplied', 'RefundApplied'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(25_000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertSame(3, StoreBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 25_000);
    }

    public function test_postgres_parallel_credits_reconstruct_from_immutable_entries(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45403);

        $results = $this->runConcurrentWorkers(4, function (int $index) use ($customer): string {
            $amount = ($index + 1) * 1000;

            $result = app(StoreBalanceLedger::class)->credit(
                Customer::query()->findOrFail($customer->id),
                $amount,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                '71'.$index,
                'balance_top_up:71'.$index.':credit',
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['Applied', 'Applied', 'Applied', 'Applied'], array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results));

        $this->assertSame(4, StoreBalanceEntry::query()->count());
        $this->assertSame(10000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 10000);
    }

    public function test_postgres_duplicate_refund_source_identity_settles_one_economic_effect(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45404);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 9000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '9001', 'balance_top_up:9001:credit');
        $ledger->debit($customer, 9000, 'order', '9001', 'order:9001:debit');

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            $result = app(StoreBalanceLedger::class)->refund(
                Customer::query()->findOrFail($customer->id),
                9000,
                'order',
                '9001',
                'order:9001:refund:attempt-'.$index,
            );

            return $result->applied ? 'Applied' : 'Replayed';
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Replayed'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame(3, StoreBalanceEntry::query()->count());
        $this->assertSame(9000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertProjectionMatchesLedger($customer, 9000);
    }

    public function test_postgres_constraints_reject_negative_balance_and_duplicate_claims(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45405);
        app(StoreBalanceLedger::class)->credit($customer, 1000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '7005', 'balance_top_up:7005:credit');
        $accountId = (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('id');

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_balance_accounts')->where('id', $accountId)->update(['balance' => -1]),
            'customer_balance_accounts_balance_nonnegative',
        );

        $this->assertDatabaseConstraintRejects(fn () => DB::table('store_balance_entries')->insert([
            'customer_balance_account_id' => $accountId,
            'kind' => StoreBalanceEntryKind::TopUpCredit->value,
            'amount' => 1000,
            'balance_after' => 2000,
            'source_type' => 'balance_top_up',
            'source_id' => '7005',
            'idempotency_key' => 'balance_top_up:7005:replay',
            'created_at' => now(),
        ]), 'store_balance_entries_source_unique');

        $this->assertDatabaseConstraintRejects(fn () => DB::table('store_balance_entries')->insert([
            'customer_balance_account_id' => $accountId,
            'kind' => StoreBalanceEntryKind::PurchaseDebit->value,
            'amount' => 0,
            'balance_after' => 1000,
            'source_type' => 'order',
            'source_id' => '7005',
            'idempotency_key' => 'order:7005:zero',
            'created_at' => now(),
        ]), 'store_balance_entries_amount_positive');

        $this->assertSame(1000, $this->balanceOf($customer));
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertProjectionMatchesLedger($customer, 1000);
    }

    public function test_postgres_account_creation_race_survives_a_rolled_back_winner(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45407);

        // Simulate the losing side of an account-creation race: the winner's
        // transaction created the row and then rolled back, so the loser must
        // not observe a half-created account.
        try {
            DB::transaction(function () use ($customer): void {
                CustomerBalanceAccount::query()->create(['customer_id' => $customer->id]);
                throw new RuntimeException('rolled back create');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->count());

        $result = app(StoreBalanceLedger::class)->credit(
            $customer,
            6000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '7007',
            'balance_top_up:7007:credit',
        );

        $this->assertTrue($result->applied);
        $this->assertSame(6000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 6000);
    }

    private function assertDatabaseConstraintRejects(callable $statement, string $expectedConstraint): void
    {
        try {
            $statement();
            $this->fail("The database constraint [{$expectedConstraint}] was expected to reject this Store Balance mutation.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expectedConstraint, $exception->getMessage());
        }
    }

    public function test_postgres_readers_never_observe_a_ledger_projection_divergence(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(45406);
        $directory = $this->createWorkspace();
        $children = [];

        try {
            DB::disconnect();
            $children[] = $this->forkWorker($directory, 0, function () use ($customer, $directory): string {
                for ($index = 1; $index <= 15; $index++) {
                    app(StoreBalanceLedger::class)->credit(
                        Customer::query()->findOrFail($customer->id),
                        1000,
                        StoreBalanceEntryKind::TopUpCredit,
                        'balance_top_up',
                        '85'.$index,
                        'balance_top_up:85'.$index.':credit',
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
                    $snapshot = app(StoreBalanceLedger::class)->snapshot(Customer::query()->findOrFail($customer->id));
                    $report = app(StoreBalanceReconciliation::class)->inspect($customer, 10);
                    if (! $snapshot->isAvailable() || ! $report->isConsistent()) {
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
        $this->assertSame(15000, $this->balanceOf($customer));
        $this->assertProjectionMatchesLedger($customer, 15000);
    }

    private function createCustomer(int $telegramId): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'en']);
        $this->createdCustomerIds[] = $customer->id;

        return $customer;
    }

    private function balanceOf(Customer $customer): int
    {
        return (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance');
    }

    private function assertProjectionMatchesLedger(Customer $customer, int $expected): void
    {
        $report = app(StoreBalanceReconciliation::class)->inspect($customer, 10);
        $this->assertTrue($report->isConsistent(), 'Projection must reconstruct from the immutable ledger entries.');
        $snapshot = app(StoreBalanceLedger::class)->snapshot($customer);
        $this->assertTrue($snapshot->isAvailable());
        $this->assertSame($expected, $snapshot->balance());
        $this->assertGreaterThanOrEqual(0, $snapshot->balance());
    }

    /** @param list<array{ok: bool, value?: string, class?: string, exit_code: int}> $results */
    private function assertWorkersSucceeded(array $results): void
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        }
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Store Balance PostgreSQL races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for Store Balance PostgreSQL races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for Store Balance PostgreSQL races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing Store Balance PostgreSQL races outside APP_ENV=testing.');
        }

        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('Store Balance PostgreSQL races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing Store Balance PostgreSQL races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("Store Balance PostgreSQL races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }

    /**
     * @param  callable(int): string  $work
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int}>
     */
    private function runConcurrentWorkers(int $count, callable $work): array
    {
        $directory = $this->createWorkspace();
        $children = [];

        try {
            DB::disconnect();
            for ($index = 0; $index < $count; $index++) {
                $children[$index] = $this->forkWorker($directory, $index, $work);
            }

            foreach (range(0, $count - 1) as $index) {
                $this->waitForMarker($directory.'/ready-'.$index);
            }
            touch($directory.'/start');

            return $this->collectResults($children, $directory);
        } finally {
            $this->cleanupWorkspace($directory);
            DB::reconnect();
        }
    }

    private function createWorkspace(): string
    {
        $directory = sys_get_temp_dir().'/store-balance-concurrency-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Unable to create Store Balance concurrency workspace.');
        }

        return $directory;
    }

    /**
     * @param  callable(int): string  $work
     */
    private function forkWorker(string $directory, int $index, callable $work): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork Store Balance concurrency worker.');
        }

        if ($pid === 0) {
            $this->runWorker(
                $work,
                $index,
                $directory.'/ready-'.$index,
                $directory.'/start',
                $directory.'/result-'.$index.'.json',
            );
        }

        return $pid;
    }

    /**
     * @param  array<int, int>  $children
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int}>
     */
    private function collectResults(array $children, string $directory): array
    {
        $results = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $resultPath = $directory.'/result-'.$index.'.json';
            $result = is_file($resultPath)
                ? json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false, 'class' => RuntimeException::class];
            $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $results[] = $result;
        }

        return $results;
    }

    private function cleanupWorkspace(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($directory);
    }

    /**
     * @param  callable(int): string  $work
     */
    private function runWorker(
        callable $work,
        int $index,
        string $readyFile,
        string $startFile,
        string $resultFile,
    ): never {
        try {
            DB::disconnect();
            touch($readyFile);
            $this->waitForMarker($startFile);
            $value = $work($index);
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'class' => $exception::class], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(1);
        }
    }

    private function waitForMarker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for Store Balance concurrency worker.');
            }
            usleep(10_000);
        }
    }
}
