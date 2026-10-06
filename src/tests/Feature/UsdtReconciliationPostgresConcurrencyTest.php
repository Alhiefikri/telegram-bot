<?php

namespace Tests\Feature;

use App\Enums\UsdtTreasuryMovementDirection;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\UsdtTreasuryMovement;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtAccountingAggregator;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtReconciliation\UsdtReconciliation;
use App\Services\UsdtReconciliation\UsdtReconciliationScope;
use App\Services\UsdtTreasury\UsdtTreasuryMovementInput;
use App\Services\UsdtTreasury\UsdtTreasuryMovements;
use App\Services\UsdtTreasury\UsdtTreasuryMovementStatus;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtReconciliationPostgresConcurrencyTest extends TestCase
{
    private const AGGREGATE_CONSUMER = 'usdt-reconciliation-race';

    /** @var list<int> */
    private array $createdCustomerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        // This class writes aggregate claims, treasury movements and balance
        // history. Cleaning only the Customer fixture leaves economic rows
        // from an earlier concurrency class in the shared disposable DB and
        // makes the final liability assertion order-dependent.
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function test_postgres_reconciliation_reports_no_false_drift_while_credits_post(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(907101);
        $directory = $this->createWorkspace();
        $children = [];

        try {
            DB::disconnect();
            $children[] = $this->forkWorker($directory, 0, function () use ($customer): string {
                $ledger = app(CustomerUsdtBalanceLedger::class);
                for ($index = 0; $index < 25; $index++) {
                    $ledger->creditVerifiedTopUp(
                        Customer::query()->findOrFail($customer->id),
                        new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000), 'topup-pg-'.$index, 'receipt-pg-'.$index, 'v1'),
                        'usdt:pg-credit-'.$index,
                    );
                }

                return 'posted-25';
            });
            $this->waitForMarker($directory.'/ready-0');
            touch($directory.'/start');

            $reconciliations = 0;
            $lastLiability = '0';
            $deadline = microtime(true) + 60;
            while (! is_file($directory.'/result-0.json') || $reconciliations < 3) {
                $report = app(UsdtReconciliation::class)->run(new UsdtReconciliationScope(limit: 50));
                $reconciliations++;

                $this->assertSame(
                    [],
                    array_map(static fn ($finding): array => $finding->toArray(), $report->accounts->findings),
                    'A consistent posting must never be observed as projection or cursor drift.',
                );

                $liability = $report->treasury->ledgerLiabilityMicro->toString();
                $this->assertGreaterThanOrEqual(0, BigInteger::of($liability)->compareTo($lastLiability));
                $lastLiability = $liability;

                if (microtime(true) > $deadline) {
                    $this->fail('Timed out while credits were posting.');
                }

                usleep(2_000);
            }

            $results = $this->collectResults($children, $directory);
            $this->assertWorkersSucceeded($results);
            $this->assertSame(['posted-25'], array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results));
            $this->assertGreaterThanOrEqual(3, $reconciliations);

            $final = app(UsdtReconciliation::class)->run(new UsdtReconciliationScope(limit: 50));
            $this->assertSame([], $final->accounts->findings);
            $this->assertSame('25000', $final->treasury->ledgerLiabilityMicro->toString());
            $this->assertSame(1, CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->count());
            $this->assertSame(25, (int) DB::table('customer_usdt_balance_entries')->where('customer_usdt_balance_account_id', CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->value('id'))->count());
            $this->assertSame(25_000, (int) DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->value('balance_micro'));
        } finally {
            $this->cleanupWorkspace($directory);
            DB::reconnect();
        }
    }

    public function test_postgres_concurrent_accounting_claims_never_double_count(): void
    {
        $this->requirePostgresConcurrency();

        $customer = $this->createCustomer(907102);
        $ledger = app(CustomerUsdtBalanceLedger::class);
        for ($index = 0; $index < 40; $index++) {
            $ledger->creditVerifiedTopUp(
                $customer,
                new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000), 'topup-aggregate-'.$index, 'receipt-aggregate-'.$index, 'v1'),
                'usdt:aggregate-'.$index,
            );
        }

        $results = $this->runConcurrentWorkers(2, function (): string {
            $aggregator = app(UsdtAccountingAggregator::class);
            $processed = 0;

            for ($round = 0; $round < 10; $round++) {
                $batch = $aggregator->catchUp(self::AGGREGATE_CONSUMER, 50);
                $processed += $batch->processedEvents;

                if ($batch->processedEvents === 0) {
                    break;
                }
            }

            return (string) $processed;
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(40, (int) $results[0]['value'] + (int) $results[1]['value']);
        $this->assertSame(40, (int) DB::table('usdt_balance_accounting_aggregate_claims')->where('consumer', self::AGGREGATE_CONSUMER)->count());
        $this->assertSame(40, (int) DB::table('usdt_balance_accounting_aggregates')->where('consumer', self::AGGREGATE_CONSUMER)->sum('entries_count'));
        $this->assertSame(40_000, (int) DB::table('usdt_balance_accounting_aggregates')->where('consumer', self::AGGREGATE_CONSUMER)->sum('amount_micro'));

        $status = app(UsdtAccountingAggregator::class)->status(self::AGGREGATE_CONSUMER);
        $this->assertTrue($status->isConsistent());
        $this->assertSame(0, $status->pendingEvents);

        $again = app(UsdtAccountingAggregator::class)->catchUp(self::AGGREGATE_CONSUMER, 50);
        $this->assertSame(0, $again->processedEvents);
    }

    public function test_postgres_concurrent_movement_recording_is_idempotent(): void
    {
        $this->requirePostgresConcurrency();

        $results = $this->runConcurrentWorkers(4, function (): string {
            $result = app(UsdtTreasuryMovements::class)->record(new UsdtTreasuryMovementInput(
                direction: UsdtTreasuryMovementDirection::Out,
                amount: UsdtMicroAmount::fromDecimalString('7.5'),
                reference: '0xconcurrent-movement',
                reason: 'external_return',
                actor: 'owner',
                occurredAt: now(),
            ));

            return $result->status->value;
        });

        $this->assertWorkersSucceeded($results);
        $statuses = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        $this->assertSame(1, count(array_filter($statuses, static fn (string $status): bool => $status === UsdtTreasuryMovementStatus::Applied->value)), json_encode($statuses, JSON_THROW_ON_ERROR));
        $this->assertSame(3, count(array_filter($statuses, static fn (string $status): bool => $status === UsdtTreasuryMovementStatus::Replayed->value)), json_encode($statuses, JSON_THROW_ON_ERROR));
        $this->assertSame(1, UsdtTreasuryMovement::query()->where('reference', '0xconcurrent-movement')->count());
    }

    /** @return list<array{ok: bool, value?: string, class?: string, exit_code: int}> */
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
        $directory = storage_path('framework/testing/usdt-reconciliation-race-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create USDT reconciliation race workspace [{$directory}].");
        }

        return $directory;
    }

    private function forkWorker(string $directory, int $index, callable $work): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork a USDT reconciliation concurrency worker.');
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

    private function runWorker(callable $work, int $index, string $readyFile, string $startFile, string $resultFile): never
    {
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

    private function waitForMarker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for a USDT reconciliation concurrency worker.');
            }
            usleep(10_000);
        }
    }

    private function cleanupWorkspace(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /**
     * @param  list<array{ok: bool, value?: string, class?: string, exit_code: int}>  $results
     */
    private function assertWorkersSucceeded(array $results): void
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'], json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    private function createCustomer(int $telegramId): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $this->createdCustomerIds[] = (int) $customer->id;

        return $customer;
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('USDT reconciliation PostgreSQL races require the pgsql test connection.');
        }
        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }
        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for USDT reconciliation PostgreSQL races.');
        }
        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for USDT reconciliation PostgreSQL races.');
        }
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing to run USDT reconciliation races outside APP_ENV=testing.');
        }
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('USDT reconciliation races require DB_CONNECTION=pgsql.');
        }
        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing to run USDT reconciliation races while DB_URL is configured.');
        }
        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("USDT reconciliation races require an isolated test database; got [{$database}].");
        }
        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }

    private function cleanupFixtures(): void
    {
        if ($this->createdCustomerIds === [] || DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Truncate bypasses the row-level immutability triggers, which is the
        // only supported way to remove append-only money tables after a test.
        DB::statement('TRUNCATE usdt_balance_accounting_aggregate_claims, usdt_balance_accounting_aggregates, usdt_balance_reconciliation_runs, usdt_treasury_movements, customer_usdt_balance_accounting_events, customer_usdt_balance_entries, customer_usdt_balance_accounts RESTART IDENTITY CASCADE');
        Customer::query()->whereIn('id', $this->createdCustomerIds)->delete();
        $this->createdCustomerIds = [];
    }
}
