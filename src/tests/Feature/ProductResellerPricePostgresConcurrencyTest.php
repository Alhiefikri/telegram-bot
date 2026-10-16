<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Pricing\ResellerPricingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\NonTransactionalDatabaseTestCase;
use Tests\Support\PostgresTestDatabase;
use Throwable;

/**
 * Admin price/tier edits versus a locked checkout snapshot on PostgreSQL: the
 * schedule a checkout charges is always one complete schedule, never a mix of
 * an old and a new one.
 */
#[Group('concurrency')]
#[Group('postgres-release')]
final class ProductResellerPricePostgresConcurrencyTest extends NonTransactionalDatabaseTestCase
{
    use RunsPostgresWorkers;

    private const OLD_RETAIL_SUBTOTAL = '20000';

    private const NEW_RETAIL_SUBTOTAL = '14000';

    private int $telegramSequence = 619_200;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->requirePostgresConcurrency('Product price');
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql' && (string) env('RUN_CONCURRENCY_TESTS', '') === '1') {
            PostgresTestDatabase::migrateFresh();
        }

        parent::tearDown();
    }

    public function test_checkout_waiting_on_an_in_flight_admin_price_edit_observes_the_complete_new_schedule(): void
    {
        [$product, $admin, $cart] = $this->fixture();
        $payload = $this->priceSectionPayload(app(AdminProductWorkspaceData::class)->version($product->fresh(), 'price'));
        $directory = $this->createWorkspace('product-price-lock');

        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $this->runLockedAdminPriceEdit($directory, (int) $product->getKey(), $admin, $payload);
        }
        DB::reconnect();

        try {
            $this->waitForMarker($directory.'/locked');
            touch($directory.'/checkout_started');

            $startedAt = microtime(true);
            $order = app(OrderCheckoutService::class)->checkout(
                Cart::query()->findOrFail($cart->id),
                CheckoutPaymentIntents::manualQris(),
                resellerContext: ResellerPricingContext::forAuthenticatedApiRequest((int) $cart->customer_id),
            );
            $elapsed = microtime(true) - $startedAt;

            $worker = $this->collectWorkerResult($pid, $directory);
        } finally {
            $this->reapWorker($pid);
            $this->cleanupWorkspace($directory);
        }

        $this->assertTrue($worker['ok'], json_encode($worker, JSON_THROW_ON_ERROR));
        $this->assertSame(200, $worker['status']);
        $this->assertGreaterThan(0.2, $elapsed, 'The checkout must wait for the in-flight Product price edit.');

        $item = $order->orderItems()->sole();
        $this->assertSame(6_000, (int) $item->unit_price);
        $this->assertSame(12_000, (int) $item->subtotal);
        $this->assertSame(12_000, (int) $order->total_amount);
        $this->assertNull($item->quantity_price_tier_minimum);

        $this->assertSame(8_000, (int) $product->fresh()->price_amount);
        $this->assertSame(6_000, (int) $product->fresh()->reseller_price_amount);
        $this->assertNull($product->fresh()->reseller_usdt_price_amount);
        $this->assertNull($product->fresh()->usdt_price_amount);
        $this->assertSame([['minimum_quantity' => 2, 'price_amount' => 7_000]], $this->tierSnapshot($product));
    }

    public function test_concurrent_admin_price_edit_and_checkout_never_charge_a_mixed_schedule(): void
    {
        foreach (range(1, 3) as $round) {
            [$product, $admin, $cart] = $this->fixture();
            $payload = $this->priceSectionPayload(app(AdminProductWorkspaceData::class)->version($product->fresh(), 'price'));

            $results = $this->runConcurrentWorkers(2, function (int $index) use ($admin, $product, $cart, $payload): string {
                if ($index === 0) {
                    $this->actingAs($admin);

                    return (string) $this->patchJson(route('admin.products.update', $product->id), $payload)->getStatusCode();
                }

                $order = app(OrderCheckoutService::class)->checkout(
                    Cart::query()->findOrFail($cart->id),
                    CheckoutPaymentIntents::manualQris(),
                );
                $item = $order->orderItems()->sole();

                return $item->subtotal.':'.($item->quantity_price_tier_minimum ?? 'none');
            }, 'product-price-race');

            $this->assertWorkersSucceeded($results);
            $this->assertSame('200', $results[0]['value']);
            $this->assertContains($results[1]['value'], [
                self::OLD_RETAIL_SUBTOTAL.':none',
                self::NEW_RETAIL_SUBTOTAL.':2',
            ], "Round {$round} charged a mixed Product price schedule.");
            $this->assertSame(8_000, (int) $product->fresh()->price_amount);
            $this->assertSame(6_000, (int) $product->fresh()->reseller_price_amount);
            $this->assertSame([['minimum_quantity' => 2, 'price_amount' => 7_000]], $this->tierSnapshot($product));
        }
    }

    /**
     * The real Admin price-section save runs inside an explicit transaction that
     * holds the Product row lock, so the checkout can only observe the committed
     * schedule. The worker signals `locked` before committing.
     */
    private function runLockedAdminPriceEdit(string $directory, int $productId, User $admin, array $payload): never
    {
        try {
            DB::reconnect();
            DB::beginTransaction();
            Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $this->actingAs($admin);
            $status = $this->patchJson(route('admin.products.update', $productId), $payload)->getStatusCode();
            touch($directory.'/locked');
            $this->waitForMarker($directory.'/checkout_started');
            usleep(300_000);
            DB::commit();
            file_put_contents($directory.'/locked-result.json', json_encode(['ok' => true, 'status' => $status], JSON_THROW_ON_ERROR), LOCK_EX);
        } catch (Throwable $exception) {
            file_put_contents($directory.'/locked-result.json', json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR), LOCK_EX);
        }

        exit(0);
    }

    /**
     * @return array{ok: bool, status?: int}
     */
    private function collectWorkerResult(int $pid, string $directory): array
    {
        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255, 'The Admin price edit worker must exit cleanly.');
        $path = $directory.'/locked-result.json';
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function reapWorker(int $pid): void
    {
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * @return array{0: Product, 1: User, 2: Cart}
     */
    private function fixture(): array
    {
        $product = Product::factory()->create([
            'price_amount' => 10_000,
            'usdt_price_amount' => null,
            'reseller_price_amount' => 9_000,
            'reseller_usdt_price_amount' => null,
            'stock' => 10,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => $this->telegramSequence++]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);

        return [$product, $admin, $cart];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceSectionPayload(string $version): array
    {
        return [
            'workspace_section' => 'price',
            'workspace_version' => $version,
            'price_amount' => 8_000,
            'reseller_price_amount' => 6_000,
            'quantity_price_tiers_present' => true,
            'quantity_price_tiers' => [['minimum_quantity' => 2, 'price_amount' => 7_000]],
        ];
    }

    /**
     * @return list<array{minimum_quantity: int, price_amount: int}>
     */
    private function tierSnapshot(Product $product): array
    {
        return $product->quantityPriceTiers()
            ->get(['minimum_quantity', 'price_amount'])
            ->map(fn ($tier): array => ['minimum_quantity' => (int) $tier->minimum_quantity, 'price_amount' => (int) $tier->price_amount])
            ->all();
    }
}
