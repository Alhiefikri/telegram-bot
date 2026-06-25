<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminOrderOperations;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
class ProductionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram', [
            'token' => 'bot-token',
            'webhook_secret' => 'secret',
            'api_base_url' => 'https://api.telegram.org',
            'default_payment_method' => 'BRIVA',
        ]);
        config()->set('services.tripay', [
            'api_key' => 'sandbox-key',
            'private_key' => 'sandbox-private',
            'merchant_code' => 'T0001',
            'base_url' => 'https://tripay.test',
            'callback_url' => 'https://example.test/api/webhook/tripay',
            'return_url' => 'https://example.test/thanks',
        ]);
        config()->set('queue.default', 'sync');

        if (! $this->shouldRunConcurrencyLane()) {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_concurrent_checkout_for_same_stock_allows_only_one_reservation(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $firstCart = $this->cartForProduct($product);
        $secondCart = $this->cartForProduct($product);

        $results = $this->runConcurrently([
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($firstCart->id), 'manual', 'manual')->id,
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($secondCart->id), 'manual', 'manual')->id,
        ]);

        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertSame([InsufficientStock::class], $this->failedResultClasses($results));

        $order = Order::with('payments')->firstOrFail();
        $product->refresh();

        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(PaymentStatus::Pending, $order->payments->first()->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(1, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(0, PaymentEvent::count());
        $this->assertNull($order->telegram_receipt_sent_at);
    }

    public function test_concurrent_duplicate_paid_callbacks_create_one_sale_and_one_receipt(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $order = $this->tripayReadyOrder(stock: 2, quantity: 1, expiresAt: now()->addHour());
        $payment = $order->payments()->firstOrFail();
        $rawPayload = json_encode($this->tripayPayload($payment), JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawPayload, 'sandbox-private');

        $results = $this->runConcurrently([
            fn () => $this->processTripayCallback($rawPayload, $signature),
            fn () => $this->processTripayCallback($rawPayload, $signature),
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));

        $order->refresh();
        $payment->refresh();
        $product = Product::firstOrFail();

        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(2, PaymentEvent::where('event_type', PaymentEventType::Callback)->whereNotNull('processed_at')->count());
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_paid_callback_racing_expiry_preserves_single_valid_inventory_outcome(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $order = $this->tripayReadyOrder(stock: 1, quantity: 1, expiresAt: now()->subMinute());
        $payment = $order->payments()->firstOrFail();
        $rawPayload = json_encode($this->tripayPayload($payment), JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawPayload, 'sandbox-private');

        $this->runConcurrently([
            fn () => $this->processTripayCallback($rawPayload, $signature),
            fn () => app(OrderStateMachine::class)->expirePendingOrder(Order::findOrFail($order->id))?->id,
        ]);

        $order->refresh();
        $payment->refresh();
        $product = Product::firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(1, PaymentEvent::where('event_type', PaymentEventType::Callback)->whereNotNull('processed_at')->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());

        if ($order->order_status === OrderStatus::Processing) {
            $this->assertSame(0, $product->stock);
            $this->assertSame(0, $product->reserved_stock);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
            $this->assertNotNull($order->telegram_receipt_sent_at);

            return;
        }

        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertNull($order->telegram_receipt_sent_at);
    }

    public function test_admin_manual_completion_racing_expiry_preserves_single_valid_inventory_outcome(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->create();
        $order = $this->manualReadyOrder(stock: 1, quantity: 1, expiresAt: now()->subMinute());

        $this->runConcurrently([
            fn () => app(AdminOrderOperations::class)->completeManualOrder(Order::findOrFail($order->id), User::findOrFail($user->id), '127.0.0.1')->id,
            fn () => app(OrderStateMachine::class)->expirePendingOrder(Order::findOrFail($order->id))?->id,
        ]);

        $order->refresh();
        $payment = $order->payments()->firstOrFail();
        $product = Product::firstOrFail();

        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(0, PaymentEvent::count());

        if ($order->order_status === OrderStatus::Completed) {
            $this->assertSame(PaymentStatus::Paid, $order->payment_status);
            $this->assertSame(PaymentStatus::Paid, $payment->status);
            $this->assertSame(0, $product->stock);
            $this->assertSame(0, $product->reserved_stock);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
            $this->assertNotNull($order->telegram_receipt_sent_at);

            return;
        }

        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payment_status);
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertNull($order->telegram_receipt_sent_at);
    }

    private function requireConcurrencyLane(): void
    {
        if (! $this->shouldRunConcurrencyLane()) {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 with a PostgreSQL test database to run production-like concurrency tests.');
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
            $this->markTestSkipped('The pcntl extension is required for process-level concurrency tests.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('The pdo_pgsql extension is required for PostgreSQL concurrency tests.');
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Production-like concurrency tests require DB_CONNECTION=pgsql.');
        }

        $database = strtolower((string) config('database.connections.pgsql.database'));
        if (! str_contains($database, 'test')) {
            $this->fail('Refusing to run concurrency tests unless the PostgreSQL database name contains "test".');
        }
    }

    /**
     * @param  list<callable(): mixed>  $callbacks
     * @return list<array{ok: bool, value?: mixed, class?: string, message?: string, exit_code: int}>
     */
    private function runConcurrently(array $callbacks): array
    {
        $runId = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir().'/telegram-bot-concurrency-'.$runId;

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
            $result = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [
                'ok' => false,
                'class' => 'RuntimeException',
                'message' => 'Child process did not write a result file.',
            ];
            $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $results[] = $result;
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
                    'class' => 'RuntimeException',
                    'message' => 'Timed out waiting for concurrency barrier.',
                ], JSON_THROW_ON_ERROR));
                exit(1);
            }

            usleep(10_000);
        }

        try {
            $value = $callback();
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($resultFile, json_encode([
                'ok' => false,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    private function successfulResultCount(array $results): int
    {
        return count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0));
    }

    /** @return list<class-string> */
    private function failedResultClasses(array $results): array
    {
        $classes = array_values(array_map(
            fn (array $result): string => (string) ($result['class'] ?? ''),
            array_filter($results, fn (array $result): bool => ! $result['ok'] || $result['exit_code'] !== 0),
        ));
        sort($classes);

        return $classes;
    }

    private function cartForProduct(Product $product, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => fake()->unique()->numberBetween(100000, 999999999)]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }

    private function manualReadyOrder(int $stock, int $quantity, mixed $expiresAt): Order
    {
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = $this->cartForProduct($product, $quantity);

        return app(OrderCheckoutService::class)->checkout($cart, 'manual', 'manual', $expiresAt);
    }

    private function tripayReadyOrder(int $stock, int $quantity, mixed $expiresAt): Order
    {
        $order = $this->manualReadyOrder($stock, $quantity, $expiresAt);
        $order->payments()->firstOrFail()->update(['provider' => 'tripay', 'method' => 'BRIVA']);

        return $order->fresh(['payments']);
    }

    private function tripayPayload(Payment $payment): array
    {
        return [
            'reference' => 'TREF-CONCURRENT',
            'merchant_ref' => $payment->merchant_ref,
            'payment_method' => 'BRIVA',
            'payment_method_code' => 'BRIVA',
            'total_amount' => $payment->amount,
            'status' => 'PAID',
            'paid_at' => now()->timestamp,
        ];
    }

    private function processTripayCallback(string $rawPayload, string $signature): int
    {
        $response = $this->call('POST', '/api/webhook/tripay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => $signature,
            'HTTP_X_CALLBACK_EVENT' => 'payment_status',
        ], $rawPayload);

        $response->assertOk();

        return $response->getStatusCode();
    }
}
