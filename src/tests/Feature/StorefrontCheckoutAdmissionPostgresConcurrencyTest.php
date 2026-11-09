<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Storefront\StorefrontCheckoutLimitReached;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
class StorefrontCheckoutAdmissionPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();
        if ((string) env('RUN_CONCURRENCY_TESTS') !== '1' || DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires explicit isolated PostgreSQL concurrency lane.');
        }
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        config()->set('services.storefront.telegram_reservation_floor', 0);
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
    }

    public function test_three_simultaneous_identical_purchases_create_one_order_invoice_and_reservation_without_shared_cache(): void
    {
        $product = Product::factory()->create(['stock' => 10, 'reserved_stock' => 0]);
        $calls = tempnam(sys_get_temp_dir(), 'checkout-730-provider-');
        Http::fake(['https://klikqris.com/api/sandbox/qris/create' => function ($request) use ($calls) {
            file_put_contents($calls, "create\n", FILE_APPEND | LOCK_EX);

            return Http::response(KlikQrisSandboxScenarios::createPayload(new Payment([
                'merchant_ref' => $request['order_id'], 'amount' => $request['amount'],
            ])));
        }]);
        try {
            $callback = fn () => $this->purchase($product->id, 'same-browser', '73000000-0000-4000-8000-000000000001');
            $results = $this->race([$callback, $callback, $callback]);
            $this->assertCount(1, array_unique(array_column($results, 'order')));
            $this->assertSame(1, Order::count());
            $this->assertSame(1, Payment::count());
            $this->assertSame(1, DB::table('storefront_checkout_admissions')->count());
            $this->assertSame(1, $product->fresh()->reserved_stock);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
            $this->assertSame("create\n", file_get_contents($calls));
            $issuedKeys = array_values(array_filter(array_column($results, 'recovery_key')));
            $this->assertCount(1, $issuedKeys);
            $this->assertTrue(app(StorefrontOrderAccess::class)->verifyKey(Order::sole(), $issuedKeys[0]));
        } finally {
            unlink($calls);
        }
    }

    public function test_distinct_simultaneous_purchases_from_one_browser_cannot_overbook_pending_capacity(): void
    {
        $product = Product::factory()->create(['stock' => 10, 'reserved_stock' => 0]);
        Http::fake(['https://klikqris.com/api/sandbox/qris/create' => fn ($request) => Http::response(KlikQrisSandboxScenarios::createPayload(new Payment([
            'merchant_ref' => $request['order_id'], 'amount' => $request['amount'],
        ])))]);
        $results = $this->race(array_map(fn ($i) => fn () => $this->purchase($product->id, 'limited-browser', sprintf('73000000-0000-4000-8000-%012d', $i)), [1, 2, 3]));
        $this->assertSame(2, count(array_filter(array_column($results, 'order'))));
        $this->assertSame(1, count(array_filter(array_column($results, 'limited'))));
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
    }

    public function test_rotating_browsers_race_for_only_one_remaining_shared_network_slot(): void
    {
        $product = Product::factory()->create(['stock' => 30, 'reserved_stock' => 0]);
        Http::fake(['https://klikqris.com/api/sandbox/qris/create' => fn ($request) => Http::response(KlikQrisSandboxScenarios::createPayload(new Payment([
            'merchant_ref' => $request['order_id'], 'amount' => $request['amount'],
        ])))]);
        for ($i = 1; $i <= 19; $i++) {
            $this->purchase($product->id, 'seed-browser-'.$i, sprintf('73000000-0000-4000-8000-%012d', $i));
        }
        $results = $this->race(array_map(fn ($i) => fn () => $this->purchase($product->id, 'race-browser-'.$i, sprintf('73000000-0000-4000-8000-%012d', $i)), [20, 21, 22]));
        $this->assertSame(1, count(array_filter(array_column($results, 'order'))));
        $this->assertSame(2, count(array_filter(array_column($results, 'limited'))));
        $this->assertSame(20, Order::count());
        $this->assertSame(20, Payment::count());
        $this->assertSame(20, $product->fresh()->reserved_stock);
    }

    private function purchase(int $productId, string $browser, string $attempt): array
    {
        $session = new Store('issue730', new ArraySessionHandler(120));
        $session->start();
        try {
            $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $productId, buyerName: 'Concurrency Buyer', termsAccepted: true, checkoutAttempt: $attempt,
            ), $browser, $session, visitorIp: '192.0.2.73');

            return ['order' => $result->orderId, 'limited' => false, 'recovery_key' => $result->recoveryKey];
        } catch (StorefrontCheckoutLimitReached) {
            return ['order' => null, 'limited' => true, 'recovery_key' => null];
        }
    }
}
