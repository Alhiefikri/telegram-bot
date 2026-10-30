<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\PollActiveSakurupiahPayment;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Services\OrderLifecycle;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahBalance;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahHistoryFilter;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

/**
 * These tests are intentionally opt-in through the PostgreSQL release and
 * concurrency gates. They refuse to migrate a non-isolated database.
 */
#[Group('concurrency')]
#[Group('postgres-release')]
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
        PostgresTestDatabase::migrateFresh();

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

    public function test_duplicate_paid_callbacks_across_postgres_workers_fulfill_once(): void
    {
        $this->prepareReleaseRaceLane();
        $payment = $this->prepareRacePayment('INV-PG-416-CALLBACK');
        $payload = $this->callbackPayload($payment);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn (): int => app(SakurupiahCallbackProcessor::class)->processCallback($payload)->id,
            fn (): int => app(SakurupiahCallbackProcessor::class)->processCallback($payload)->id,
        ]);
        DB::reconnect();

        $this->assertSame(2, $this->successfulWorkerCount($results), json_encode($results, JSON_THROW_ON_ERROR));
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 930003],
            ]),
        ]);
        (new SendTelegramReceipt((string) $payment->order_id))->handle(
            app(TelegramOutboundEffectService::class),
            app(ReceiptDeliveryPipeline::class),
        );

        $this->assertFulfillmentCardinalities($payment);
    }

    public function test_callback_reconciliation_and_expiry_race_preserves_one_valid_terminal_outcome(): void
    {
        $this->prepareReleaseRaceLane();
        if (env('RUN_SAKURUPIAH_REDIS_TESTS') === '1' && extension_loaded('redis')) {
            config()->set('cache.default', 'redis');
            config()->set('database.redis.cache.host', env('REDIS_HOST', '127.0.0.1'));
            config()->set('database.redis.cache.port', (int) env('REDIS_PORT', 6379));
            config()->set('database.redis.cache.database', 7);
            config()->set('database.redis.cache.password', null);
            config()->set('database.redis.cache.url', null);
            Cache::purge('redis');
            Cache::flush();
        }
        $payment = $this->prepareRacePayment('INV-PG-416-MIXED');
        $payload = $this->callbackPayload($payment);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn (): int => app(SakurupiahCallbackProcessor::class)->processCallback($payload)->id,
            function () use ($payment): string {
                (new PollActiveSakurupiahPayment((string) $payment->id))->handle(app(SakurupiahCallbackProcessor::class));

                return 'poll_complete';
            },
            fn (): string => $this->expireRacePayment((string) $payment->id),
        ]);
        DB::reconnect();

        $this->assertSame(3, $this->successfulWorkerCount($results), json_encode($results, JSON_THROW_ON_ERROR));

        $freshPayment = $payment->fresh();
        $order = Order::findOrFail($payment->order_id);
        $saleCount = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->where('type', InventoryMovementType::Sale)
            ->count();
        $releaseCount = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->where('type', InventoryMovementType::Release)
            ->count();

        $paidOutcome = $freshPayment->status === PaymentStatus::Paid
            && $order->order_status === OrderStatus::Completed
            && $saleCount === 1
            && $releaseCount === 0;
        $expiredOutcome = $order->order_status === OrderStatus::Expired
            && in_array($freshPayment->status, [PaymentStatus::Expired, PaymentStatus::Paid], true)
            && $saleCount === 0
            && $releaseCount === 1;

        $this->assertTrue($paidOutcome || $expiredOutcome, json_encode([
            'results' => $results,
            'payment_status' => $freshPayment->status->value,
            'order_status' => $order->order_status->value,
            'sale_count' => $saleCount,
            'release_count' => $releaseCount,
        ], JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(1, SalesProofPost::query()->where('order_id', $order->id)->count());
        $this->assertLessThanOrEqual(1, ProductStockItem::query()->where('product_id', $this->raceProductId($order))->whereNotNull('order_item_id')->count());
        foreach ([
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            TelegramOutboundEffectService::TYPE_ADMIN_PAID,
            TelegramOutboundEffectService::TYPE_SALES_PROOF,
        ] as $effectType) {
            $this->assertLessThanOrEqual(
                1,
                TelegramOutboundEffect::query()
                    ->where('effect_type', $effectType)
                    ->where('source_id', (string) $order->id)
                    ->count(),
            );
        }
    }

    private function prepareReleaseRaceLane(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL contention check runs only with the pgsql test connection.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL contention check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        PostgresTestDatabase::migrateFresh();

        config()->set('queue.default', 'sync');
        config()->set('cache.default', 'database');
        config()->set('services.telegram.token', 'bot-token');
        Cache::purge('database');
        $this->prepareEffectTable();
        $this->configureReadySakurupiah();
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'admin_telegram_chat_id' => '123456',
            'is_active' => true,
        ]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        Queue::fake();
        app()->bind(
            SakurupiahProviderAdapter::class,
            fn (): DatabaseBackedSakurupiahProviderAdapter => new DatabaseBackedSakurupiahProviderAdapter,
        );
    }

    private function prepareRacePayment(string $merchantRef): Payment
    {
        $amount = 12_000;
        $customer = Customer::factory()->create([
            'telegram_id' => 123456,
            'phone_number' => '08123456789',
        ]);
        $order = Order::factory()->for($customer, 'customer')->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => $amount,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 1,
            'price_amount' => $amount,
        ]);
        $orderItem = OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $orderItem->id,
            'secret' => 'race-buyer@example.test|race-password',
        ]);
        InventoryMovement::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation,
            'quantity' => 1,
            'notes' => 'Order reservation',
            'created_at' => now(),
        ]);

        return Payment::factory()->for($order, 'order')->create([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => $merchantRef,
            'provider_reference' => 'trx-pg-416',
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ]);
    }

    /** @return array{trx_id:string,merchant_ref:string,status:string,status_kode:int} */
    private function callbackPayload(Payment $payment): array
    {
        return [
            'trx_id' => (string) $payment->provider_reference,
            'merchant_ref' => (string) $payment->merchant_ref,
            'status' => 'berhasil',
            'status_kode' => 1,
        ];
    }

    private function expireRacePayment(string $paymentId): string
    {
        try {
            $result = app(OrderLifecycle::class)->applyPaymentExpired(Payment::findOrFail($paymentId));

            return $result->newlyApplied ? 'expired' : 'already_terminal';
        } catch (InvalidOrderTransition) {
            return 'already_terminal';
        }
    }

    /** @param list<array{ok:bool,exit_code:int}> $results */
    private function successfulWorkerCount(array $results): int
    {
        return count(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0,
        ));
    }

    private function assertFulfillmentCardinalities(Payment $payment): void
    {
        $freshPayment = $payment->fresh();
        $order = Order::findOrFail($payment->order_id);
        $productId = $this->raceProductId($order);

        $this->assertSame(PaymentStatus::Paid, $freshPayment->status);
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame(1, InventoryMovement::query()->where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, ProductStockItem::query()->where('product_id', $productId)->whereNotNull('order_item_id')->count());
        $this->assertSame(1, SalesProofPost::query()->where('order_id', $order->id)->count());

        foreach ([
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            TelegramOutboundEffectService::TYPE_ADMIN_PAID,
            TelegramOutboundEffectService::TYPE_SALES_PROOF,
        ] as $effectType) {
            $this->assertSame(
                1,
                TelegramOutboundEffect::query()
                    ->where('effect_type', $effectType)
                    ->where('source_id', (string) $order->id)
                    ->count(),
            );
        }
    }

    private function raceProductId(Order $order): int
    {
        return (int) $order->orderItems()->value('product_id');
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
        if (config('cache.default') === 'redis') {
            app('redis')->purge('cache');
            Cache::purge('redis');
        }
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
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
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
        if ($trxId !== 'trx-pg-416') {
            throw new RuntimeException('The contention test received an unexpected provider reference.');
        }

        usleep(150_000);

        return new SakurupiahStatus(
            trxId: $trxId,
            status: SakurupiahTransactionStatus::Successful,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            amount: SakurupiahAmount::from(12000),
            sanitizedPayload: [
                'trx_id' => $trxId,
                'payment_status' => SakurupiahTransactionStatus::Successful->value,
                'amount' => '12000',
            ],
        );
    }

    public function transactionHistory(?SakurupiahHistoryFilter $filter = null): array
    {
        throw new RuntimeException('The contention test does not inspect history.');
    }
}
