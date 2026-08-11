<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\CheckoutMaintenanceConflict;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\OrderCheckoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
class CheckoutMaintenancePostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->shouldRunConcurrencyLane()) {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_checkout_that_locks_maintenance_first_commits_before_activation(): void
    {
        $this->requireConcurrencyLane();
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $signal = $this->temporarySignal();

        $results = $this->runConcurrently([
            function () use ($cart, $signal): string {
                return DB::transaction(function () use ($cart, $signal): string {
                    Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
                    app(CheckoutMaintenance::class)->guardNewOrder();
                    touch($signal);
                    usleep(250_000);

                    return (string) app(OrderCheckoutService::class)
                        ->checkout(Cart::findOrFail($cart->id), CheckoutPaymentIntents::manualQris())
                        ->getKey();
                }, 5);
            },
            function () use ($owner, $signal): bool {
                $this->waitForSignal($signal);

                return app(CheckoutMaintenance::class)
                    ->change(true, 'Activation follows checkout.', 0, User::findOrFail($owner->id), null)
                    ->changed;
            },
        ]);

        @unlink($signal);
        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));
        $this->assertSame(1, Order::query()->count());
        $this->assertTrue((bool) $this->resultValues($results)[1]);
        $this->assertTrue((bool) CheckoutMaintenanceSetting::query()->firstOrFail()->is_active);
    }

    public function test_activation_that_locks_maintenance_first_rejects_checkout_after_commit(): void
    {
        $this->requireConcurrencyLane();
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $signal = $this->temporarySignal();

        $results = $this->runConcurrently([
            function () use ($owner, $signal): bool {
                return DB::transaction(function () use ($owner, $signal): bool {
                    $changed = app(CheckoutMaintenance::class)
                        ->change(true, 'Activation wins checkout.', 0, User::findOrFail($owner->id), null)
                        ->changed;
                    touch($signal);
                    usleep(250_000);

                    return $changed;
                }, 5);
            },
            function () use ($cart, $signal): string {
                $this->waitForSignal($signal);

                return (string) app(OrderCheckoutService::class)
                    ->checkout(Cart::findOrFail($cart->id), CheckoutPaymentIntents::manualQris())
                    ->getKey();
            },
        ]);

        @unlink($signal);
        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertSame([CheckoutMaintenanceActive::class], $this->failedResultClasses($results));
        $this->assertSame(0, Order::query()->count());
        $this->assertTrue((bool) CheckoutMaintenanceSetting::query()->firstOrFail()->is_active);
    }

    public function test_concurrent_activation_with_same_version_commits_once_and_conflicts_once(): void
    {
        $this->requireConcurrencyLane();
        $firstOwner = User::factory()->create(['role' => UserRole::Owner]);
        $secondOwner = User::factory()->create(['role' => UserRole::Owner]);
        $signal = $this->temporarySignal();

        $results = $this->runConcurrently([
            function () use ($firstOwner, $signal): bool {
                return DB::transaction(function () use ($firstOwner, $signal): bool {
                    $changed = app(CheckoutMaintenance::class)
                        ->change(true, 'First activation wins.', 0, User::findOrFail($firstOwner->id), null)
                        ->changed;
                    touch($signal);
                    usleep(250_000);

                    return $changed;
                }, 5);
            },
            function () use ($secondOwner, $signal): bool {
                $this->waitForSignal($signal);

                return app(CheckoutMaintenance::class)
                    ->change(true, 'Second activation must conflict.', 0, User::findOrFail($secondOwner->id), null)
                    ->changed;
            },
        ]);

        @unlink($signal);
        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertSame([CheckoutMaintenanceConflict::class], $this->failedResultClasses($results));
        $this->assertSame(1, CheckoutMaintenanceSetting::query()->firstOrFail()->version);
        $this->assertSame(1, AuditLog::query()->where('action', 'checkout_maintenance.activated')->count());
    }

    private function cartForProduct(Product $product): Cart
    {
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        return $cart->fresh();
    }

    private function temporarySignal(): string
    {
        $path = sys_get_temp_dir().'/checkout-maintenance-'.bin2hex(random_bytes(8));
        @unlink($path);

        return $path;
    }

    /** @param list<callable(): mixed> $callbacks */
    private function runConcurrently(array $callbacks): array
    {
        $runId = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir().'/checkout-maintenance-concurrency-'.$runId;
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Unable to create concurrency test workspace.');
        }

        $releaseFile = $directory.'/release';
        $children = [];
        DB::disconnect();

        foreach ($callbacks as $index => $callback) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork concurrency test process.');
            }
            if ($pid === 0) {
                $this->runChildProcess($callback, $releaseFile, $directory.'/result-'.$index.'.json');
            }
            $children[$index] = $pid;
        }

        touch($releaseFile);
        $results = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $path = $directory.'/result-'.$index.'.json';
            $results[] = is_file($path)
                ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false, 'class' => 'RuntimeException', 'message' => 'Child did not write a result.'];
            $results[$index]['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::reconnect();

        return $results;
    }

    private function runChildProcess(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();
        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode([
                    'ok' => false,
                    'class' => \RuntimeException::class,
                    'message' => 'Timed out waiting for concurrency barrier.',
                ], JSON_THROW_ON_ERROR));
                exit(1);
            }

            usleep(5_000);
        }
        try {
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $callback()], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode([
                'ok' => false,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    private function waitForSignal(string $signal): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($signal)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Timed out waiting for concurrency signal.');
            }

            usleep(5_000);
        }
    }

    private function requireConcurrencyLane(): void
    {
        if (! $this->shouldRunConcurrencyLane()) {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 with a PostgreSQL test database to run concurrency tests.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function shouldRunConcurrencyLane(): bool
    {
        return (string) env('RUN_CONCURRENCY_TESTS', '') === '1';
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for concurrency tests.');
        }
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('The pdo_pgsql extension is required for concurrency tests.');
        }
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Concurrency tests require DB_CONNECTION=pgsql.');
        }

        $database = strtolower((string) config('database.connections.pgsql.database'));
        if (! str_contains($database, 'test')) {
            $this->fail('Refusing to run concurrency tests unless the PostgreSQL database name contains "test".');
        }
    }

    private function successfulResultCount(array $results): int
    {
        return count(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === true));
    }

    /** @return list<string> */
    private function failedResultClasses(array $results): array
    {
        $classes = array_map(fn (array $result): string => (string) ($result['class'] ?? ''), array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === false));
        sort($classes);

        return $classes;
    }

    /** @return list<mixed> */
    private function resultValues(array $results): array
    {
        return array_map(fn (array $result): mixed => $result['value'] ?? null, $results);
    }
}
