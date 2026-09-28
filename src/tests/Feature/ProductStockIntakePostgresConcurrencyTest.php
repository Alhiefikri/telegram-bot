<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\CredentialIdentity;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeSubmission;
use App\Services\ProductStockItemManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
final class ProductStockIntakePostgresConcurrencyTest extends TestCase
{
    private const FINGERPRINT_KEY = 'postgres-stock-intake-test-fingerprint-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', self::FINGERPRINT_KEY);
    }

    public function test_same_idempotency_key_commits_one_intake_and_replays_one_result(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);
        $operationKey = (string) Str::uuid();
        $submission = ProductStockIntakeSubmission::structured([
            $this->account('same-key@example.test', 'same-key-password'),
        ], $operationKey);

        $results = $this->runConcurrently([
            fn (): array => $this->runIntakeWorker($product->id, $admin->id, $submission),
            fn (): array => $this->runIntakeWorker($product->id, $admin->id, $submission),
        ]);

        $outcomes = array_column($this->successfulWorkerResults($results), 'outcome');
        sort($outcomes);

        $this->assertSame(['created', 'replayed'], $outcomes, $this->safeResultsJson($results));
        $this->assertSame(1, ProductStockItem::query()->count());
        $this->assertSame(0, CredentialIdentity::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, DB::table('product_stock_intake_operations')->count());
    }

    public function test_duplicate_email_race_commits_both_intakes(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);
        $submissions = [
            ProductStockIntakeSubmission::structured([$this->account('duplicate-race@example.test', 'password-a')]),
            ProductStockIntakeSubmission::structured([$this->account(' DUPLICATE-RACE@example.test ', 'password-b')]),
        ];

        $results = $this->runConcurrently([
            fn (): array => $this->runIntakeWorker($product->id, $admin->id, $submissions[0]),
            fn (): array => $this->runIntakeWorker($product->id, $admin->id, $submissions[1]),
        ]);

        $successful = $this->successfulWorkerResults($results);
        $outcomes = array_column($successful, 'outcome');
        sort($outcomes);

        $this->assertSame(['created', 'created'], $outcomes, $this->safeResultsJson($results));
        $this->assertSame(2, ProductStockItem::query()->count());
        $this->assertSame(0, CredentialIdentity::query()->count());
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(2, DB::table('product_stock_intake_operations')->count());
        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_intake_racing_checkout_preserves_numeric_and_credential_availability(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'existing-race@example.test|existing-password']);
        $admin = User::factory()->create(['role' => 'admin']);
        $cart = $this->cartFor($product, 781001);
        $submission = ProductStockIntakeSubmission::structured([$this->account('intake-checkout@example.test', 'intake-password')]);

        $results = $this->runConcurrently([
            fn (): array => $this->runIntakeWorker($product->id, $admin->id, $submission),
            fn (): array => $this->runCheckoutWorker($cart->id),
        ]);

        $successful = $this->successfulWorkerResults($results);
        $this->assertCount(2, $successful, $this->safeResultsJson($results));
        $this->assertContains('created', array_column($successful, 'outcome'), $this->safeResultsJson($results));
        $this->assertContains('checkout_created', array_column($successful, 'outcome'), $this->safeResultsJson($results));

        $storedProduct = $product->fresh();
        $this->assertSame(2, $storedProduct->stock);
        $this->assertSame(1, $storedProduct->reserved_stock);
        $this->assertSame(1, ProductStockItem::query()->whereNull('order_item_id')->whereNull('retired_at')->count());
        $this->assertSame(1, ProductStockItem::query()->whereNotNull('order_item_id')->count());
        $this->assertSame(0, CredentialIdentity::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_structured_edit_racing_checkout_either_updates_before_allocation_or_is_rejected_after_allocation(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);
        app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([$this->account('edit-before@example.test', 'old-password')]),
            $admin,
            dispatchNotifications: false,
        );
        $product->refresh();
        $stockItem = ProductStockItem::query()->sole();
        $cart = $this->cartFor($product, 781002);

        $results = $this->runConcurrently([
            fn (): array => $this->runEditWorker($product->id, $stockItem->id, $admin->id),
            fn (): array => $this->runCheckoutWorker($cart->id),
        ]);

        $successful = $this->successfulWorkerResults($results);
        $this->assertContains('checkout_created', array_column($successful, 'outcome'), $this->safeResultsJson($results));

        $storedItem = ProductStockItem::query()->sole();
        $payload = $storedItem->credential_payload;
        $this->assertIsArray($payload);
        $this->assertContains($payload['email'], ['edit-before@example.test', 'edit-after@example.test']);
        $this->assertNotNull($storedItem->order_item_id);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(0, CredentialIdentity::query()->count());
        $this->assertSame(1, Order::query()->count());

    }

    public function test_structured_delete_racing_checkout_preserves_registry_and_stock_invariants(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);
        app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([$this->account('delete-race@example.test', 'delete-password')]),
            $admin,
            dispatchNotifications: false,
        );
        $product->refresh();
        $stockItem = ProductStockItem::query()->sole();
        $cart = $this->cartFor($product, 781003);

        $results = $this->runConcurrently([
            fn (): array => $this->runDeleteWorker($product->id, $stockItem->id, $admin->id),
            fn (): array => $this->runCheckoutWorker($cart->id),
        ]);

        $successful = $this->successfulWorkerResults($results);
        $outcomes = array_column($successful, 'outcome');
        $this->assertCount(2, $successful, $this->safeResultsJson($results));
        $this->assertTrue(
            in_array('deleted', $outcomes, true) || in_array('checkout_created', $outcomes, true),
            $this->safeResultsJson($results),
        );

        $this->assertSame(0, CredentialIdentity::query()->count());
        $storedItem = ProductStockItem::query()->first();
        if (in_array('deleted', $outcomes, true)) {
            $this->assertNull($storedItem);
            $this->assertSame(0, $product->fresh()->stock);
            $this->assertSame(0, $product->fresh()->reserved_stock);
            $this->assertSame(0, Order::query()->count());
        } else {
            $this->assertNotNull($storedItem);
            $this->assertNotNull($storedItem->order_item_id);
            $this->assertSame(1, $product->fresh()->stock);
            $this->assertSame(1, $product->fresh()->reserved_stock);
            $this->assertSame(1, Order::query()->count());
        }
    }

    /** @return array{email:string,password:string,access_method:string,access_value:null} */
    private function account(string $email, string $password): array
    {
        return [
            'email' => $email,
            'password' => $password,
            'access_method' => CredentialAccessMethod::None->value,
            'access_value' => null,
        ];
    }

    private function cartFor(Product $product, int $telegramId): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        return $cart->fresh();
    }

    /** @return array{outcome:string,result?:array<string,mixed>,fields?:list<string>} */
    private function runIntakeWorker(int $productId, int $adminId, ProductStockIntakeSubmission $submission): array
    {
        try {
            $result = app(ProductStockIntake::class)->submit(
                Product::query()->findOrFail($productId),
                $submission,
                User::query()->findOrFail($adminId),
                dispatchNotifications: false,
            );

            return [
                'outcome' => $result->idempotentReplay ? 'replayed' : 'created',
                'result' => $result->toArray(),
            ];
        } catch (ValidationException $exception) {
            return [
                'outcome' => 'duplicate',
                'fields' => array_keys($exception->errors()),
            ];
        }
    }

    /** @return array{outcome:string,order_id?:int} */
    private function runCheckoutWorker(int $cartId): array
    {
        try {
            $order = app(OrderCheckoutService::class)->checkout(
                Cart::query()->findOrFail($cartId),
                CheckoutPaymentIntents::manualQris(),
            );

            return ['outcome' => 'checkout_created', 'order_id' => (int) $order->id];
        } catch (InsufficientStock) {
            return ['outcome' => 'checkout_rejected'];
        } catch (Throwable $exception) {
            return ['outcome' => 'checkout_failed', 'class' => $exception::class];
        }
    }

    /** @return array{outcome:string} */
    private function runEditWorker(int $productId, int $stockItemId, int $adminId): array
    {
        try {
            app(ProductStockItemManager::class)->updateAvailableStructured(
                Product::query()->findOrFail($productId),
                ProductStockItem::query()->findOrFail($stockItemId),
                $this->account('edit-after@example.test', 'new-password'),
                User::query()->findOrFail($adminId),
            );

            return ['outcome' => 'edited'];
        } catch (ModelNotFoundException|ValidationException) {
            return ['outcome' => 'edit_rejected'];
        }
    }

    /** @return array{outcome:string} */
    private function runDeleteWorker(int $productId, int $stockItemId, int $adminId): array
    {
        try {
            app(ProductStockItemManager::class)->deleteAvailable(
                Product::query()->findOrFail($productId),
                ProductStockItem::query()->findOrFail($stockItemId),
                User::query()->findOrFail($adminId),
            );

            return ['outcome' => 'deleted'];
        } catch (ModelNotFoundException|ValidationException) {
            return ['outcome' => 'delete_rejected'];
        }
    }

    private function requireConcurrencyLane(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Product stock PostgreSQL races run only with the pgsql test connection.');
        }
        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run product stock process races.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Product stock concurrency requires pcntl process support.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function freshConcurrencySchema(): void
    {
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing PostgreSQL stock concurrency migrations outside APP_ENV=testing.');
        }

        $exitCode = PostgresTestDatabase::migrateFresh();
        $this->assertSame(0, $exitCode);
        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('The pdo_pgsql extension is required for product stock concurrency.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");
        if ((string) config("database.connections.{$connection}.url", '') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing PostgreSQL stock concurrency setup while DB_URL is configured.');
        }
        if (! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("Refusing PostgreSQL stock concurrency setup against non-test database [{$database}] on [{$host}].");
        }

        $actual = (string) DB::selectOne('select current_database() as database')->database;
        if ($actual !== $database) {
            $this->fail("Refusing PostgreSQL stock concurrency setup because connected database [{$actual}] differs from [{$database}].");
        }
    }

    /**
     * @param  list<callable():array<string,mixed>>  $callbacks
     * @return list<array{ok:bool,value?:array<string,mixed>,class?:string,message?:string,exit_code:int}>
     */
    private function runConcurrently(array $callbacks): array
    {
        $directory = storage_path('framework/testing/product-stock-intake-concurrency-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Unable to create product stock concurrency workspace.');
        }

        $releaseFile = $directory.'/release';
        $children = [];
        DB::disconnect();

        try {
            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->terminateAndReap($children);
                    $this->fail('Unable to fork product stock concurrency worker.');
                }
                if ($pid === 0) {
                    $this->runChild($callback, $releaseFile, $directory.'/result-'.$index.'.json');
                }
                $children[$index] = $pid;
            }

            touch($releaseFile);
            $statuses = $this->waitForChildren($children, 20.0);
            $unfinished = array_diff_key($children, $statuses);
            if ($unfinished !== []) {
                $this->terminateAndReap($unfinished);
                $this->fail('Product stock concurrency workers timed out.');
            }

            $results = [];
            foreach ($children as $index => $pid) {
                $path = $directory.'/result-'.$index.'.json';
                $result = is_file($path)
                    ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                    : ['ok' => false, 'class' => RuntimeException::class, 'message' => 'Worker did not write a result.'];
                $status = $statuses[$index];
                $result['exit_code'] = is_int($status) && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
                $results[] = $result;
            }

            return $results;
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param array<int,int> $children @return array<int,int> */
    private function waitForChildren(array $children, float $timeout): array
    {
        $statuses = [];
        $deadline = microtime(true) + $timeout;
        while (count($statuses) < count($children) && microtime(true) < $deadline) {
            foreach (array_diff_key($children, $statuses) as $index => $pid) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === $pid || $waited === -1) {
                    $statuses[$index] = $waited === $pid ? $status : -1;
                }
            }
            if (count($statuses) < count($children)) {
                usleep(10_000);
            }
        }

        return $statuses;
    }

    /** @param array<int,int> $children */
    private function terminateAndReap(array $children): void
    {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
        foreach ($children as $pid) {
            $status = 0;
            @pcntl_waitpid($pid, $status);
        }
    }

    /** @param callable():array<string,mixed> $callback */
    private function runChild(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();
        config()->set('services.credentials.identity_fingerprint_key', self::FINGERPRINT_KEY);

        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode([
                    'ok' => false,
                    'class' => RuntimeException::class,
                    'message' => 'Worker barrier timed out.',
                ], JSON_THROW_ON_ERROR));
                exit(1);
            }
            usleep(10_000);
        }

        try {
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $callback()], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $exception) {
            // Never put exception messages in the race artifact: a future
            // validation/driver message must not become a credential sink.
            file_put_contents($resultFile, json_encode([
                'ok' => false,
                'class' => $exception::class,
                'message' => 'Worker failed without diagnostic payload.',
            ], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    /** @return list<array<string,mixed>> */
    private function successfulWorkerResults(array $results): array
    {
        return array_values(array_map(
            static fn (array $result): array => $result['value'] ?? [],
            array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === true && ($result['exit_code'] ?? 1) === 0),
        ));
    }

    /** @param list<array<string,mixed>> $results */
    private function safeResultsJson(array $results): string
    {
        return json_encode(array_map(static fn (array $result): array => [
            'ok' => $result['ok'] ?? false,
            'outcome' => $result['value']['outcome'] ?? null,
            'class' => $result['class'] ?? null,
            'exit_code' => $result['exit_code'] ?? null,
        ], $results), JSON_THROW_ON_ERROR);
    }
}
