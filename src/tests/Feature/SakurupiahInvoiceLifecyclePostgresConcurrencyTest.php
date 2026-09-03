<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahBalance;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahHistoryFilter;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * This test is intentionally opt-in through phpunit.postgres.xml. It refuses
 * to migrate a non-isolated database and skips when the normal SQLite suite is
 * running.
 */
class SakurupiahInvoiceLifecyclePostgresConcurrencyTest extends TestCase
{
    public function test_same_payment_create_is_single_flight_across_postgres_workers(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL contention check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL contention check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--drop-views' => true,
            '--drop-types' => true,
        ]);

        config()->set('cache.default', 'database');
        Cache::purge('database');
        $this->prepareEffectTable();
        $this->configureReadySakurupiah();
        app()->bind(SakurupiahProviderAdapter::class, fn (): DatabaseBackedSakurupiahProviderAdapter => new DatabaseBackedSakurupiahProviderAdapter);

        $customer = Customer::factory()->create(['phone_number' => '08123456789']);
        $order = Order::factory()->for($customer, 'customer')->create([
            'total_amount' => 12000,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);
        $payment = Payment::factory()->for($order, 'order')->create([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => 'INV-PG-411',
            'method' => 'QRIS',
            'amount' => 12000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ]);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn (): string => app(SakurupiahInvoiceLifecycleService::class)
                ->lifecycle(Payment::findOrFail($payment->id))
                ->outcome()
                ->value,
            fn (): string => app(SakurupiahInvoiceLifecycleService::class)
                ->lifecycle(Payment::findOrFail($payment->id))
                ->outcome()
                ->value,
        ]);
        DB::reconnect();

        $successfulWorkers = array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0,
        );
        $this->assertCount(2, $successfulWorkers, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('sakurupiah_test_provider_effects')->count());
        $this->assertSame(1, Payment::findOrFail($payment->id)->paymentEvents()->where('event_type', 'provider_create')->count());
    }

    private function configureReadySakurupiah(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'key-test',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
    }

    private function prepareEffectTable(): void
    {
        Schema::dropIfExists('sakurupiah_test_provider_effects');
        Schema::create('sakurupiah_test_provider_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_ref');
            $table->timestamps();
        });
    }

    private function guardIsolatedPostgresTestingDatabase(): void
    {
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing PostgreSQL concurrency migration outside APP_ENV=testing.');
        }

        $connection = config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL contention check runs only with the pgsql test connection.');
        }

        $configuredUrl = (string) config("database.connections.{$connection}.url");
        $environmentUrl = (string) env('DB_URL', '');
        if ($configuredUrl !== '' || $environmentUrl !== '') {
            $this->fail('Refusing PostgreSQL contention setup while DB_URL is configured.');
        }

        $database = (string) config("database.connections.{$connection}.database");
        if (! $this->isStrictTestingDatabaseName($database)) {
            $this->fail("PostgreSQL contention check requires an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database || ! $this->isStrictTestingDatabaseName($actualDatabase)) {
            $this->fail('Connected PostgreSQL database is not the configured isolated test database.');
        }
    }

    private function isStrictTestingDatabaseName(string $database): bool
    {
        return preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;
    }

    /**
     * @param  list<callable(): mixed>  $callbacks
     * @return list<array{ok: bool, value?: mixed, class?: string, message?: string, exit_code: int}>
     */
    private function runConcurrently(array $callbacks): array
    {
        $directory = storage_path('framework/testing/sakurupiah-lifecycle-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Unable to create concurrency test workspace.');
        }

        $children = [];
        try {
            $releaseFile = $directory.'/release';
            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->terminateAndReapChildren($children);
                    $this->fail('Unable to fork PostgreSQL contention worker.');
                }

                if ($pid === 0) {
                    $this->runChildProcess($callback, $releaseFile, $directory.'/result-'.$index.'.json');
                }

                $children[$index] = $pid;
            }

            touch($releaseFile);
            $statuses = $this->waitForChildren($children, 20.0);
            $unfinished = array_diff_key($children, $statuses);
            if ($unfinished !== []) {
                $this->terminateAndReapChildren($unfinished);
                $this->fail('PostgreSQL contention workers timed out.');
            }

            $results = [];
            foreach ($children as $index => $pid) {
                $path = $directory.'/result-'.$index.'.json';
                $result = is_file($path)
                    ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                    : ['ok' => false, 'class' => RuntimeException::class, 'message' => 'Worker result missing.'];
                $status = $statuses[$index];
                $result['exit_code'] = is_int($status) && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
                $results[] = $result;
            }

            return $results;
        } finally {
            $this->cleanupConcurrencyDirectory($directory);
        }
    }

    /** @param array<int, int> $children @return array<int, int|null> */
    private function waitForChildren(array $children, float $timeoutSeconds): array
    {
        $statuses = [];
        $deadline = microtime(true) + $timeoutSeconds;
        while (count($statuses) < count($children) && microtime(true) < $deadline) {
            foreach ($children as $index => $pid) {
                if (array_key_exists($index, $statuses)) {
                    continue;
                }
                $status = 0;
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === $pid || $waited === -1) {
                    $statuses[$index] = $waited === $pid ? $status : null;
                }
            }
            if (count($statuses) < count($children)) {
                usleep(10_000);
            }
        }

        return $statuses;
    }

    /** @param array<int, int> $children */
    private function terminateAndReapChildren(array $children): void
    {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
        $this->waitForChildren($children, 2.0);
    }

    private function cleanupConcurrencyDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function runChildProcess(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();
        Cache::purge('database');
        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode(['ok' => false, 'class' => RuntimeException::class, 'message' => 'Contention barrier timed out.'], JSON_THROW_ON_ERROR));
                exit(1);
            }
            usleep(10_000);
        }

        try {
            $value = $callback();
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }
}

final class DatabaseBackedSakurupiahProviderAdapter implements SakurupiahProviderAdapter
{
    public function listChannels(): array
    {
        throw new RuntimeException('The contention test does not observe channels.');
    }

    public function checkBalance(): SakurupiahBalance
    {
        throw new RuntimeException('The contention test does not check balance.');
    }

    public function createInvoice(SakurupiahCreateInvoice $invoice): SakurupiahInvoice
    {
        DB::table('sakurupiah_test_provider_effects')->insert([
            'merchant_ref' => $invoice->merchantRef,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        usleep(150_000);

        return new SakurupiahInvoice(
            trxId: 'trx-pg-411',
            merchantRef: $invoice->merchantRef,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRIS,
            status: SakurupiahTransactionStatus::Pending,
            total: SakurupiahAmount::from(12084),
            amount: SakurupiahAmount::from(12000),
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice',
        );
    }

    public function transactionStatus(string $trxId): SakurupiahStatus
    {
        throw new RuntimeException('The contention test must not recover a created invoice.');
    }

    public function transactionHistory(?SakurupiahHistoryFilter $filter = null): array
    {
        throw new RuntimeException('The contention test does not inspect history.');
    }
}
