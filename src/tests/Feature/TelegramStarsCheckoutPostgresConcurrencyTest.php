<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStock;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Services\OrderCheckoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsStarsCheckouts;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramStarsCheckoutPostgresConcurrencyTest extends TestCase
{
    use BuildsStarsCheckouts;
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Telegram Stars checkout');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $this->enableStars();
    }

    public function test_cart_and_direct_buyers_race_for_the_last_credential(): void
    {
        $product = $this->starsProduct();
        $cartBuyer = Customer::factory()->create();
        $directBuyer = Customer::factory()->create();
        $cart = $this->starsCart($cartBuyer, $product);
        $cartIntent = $this->starsIntent($cartBuyer, $cart, 'cart-stock-race');
        $directIntent = $this->starsIntent($directBuyer, $product, 'direct-stock-race', 1);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($cart, $product, $cartIntent, $directIntent): string {
            $checkout = app(OrderCheckoutService::class);

            return (string) ($index === 0
                ? $checkout->checkout($cart, $cartIntent, checkoutKey: 'cart-stock-race')
                : $checkout->checkoutTelegramStarsDirect($product, 1, $directIntent))->id;
        }, 'stars-stock');

        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']));
        $this->assertSame([InsufficientStock::class], array_column($results, 'class'));
        $this->assertOneReservation();
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, $product->stockItems()->whereNotNull('order_item_id')->count());
    }

    public function test_concurrent_direct_replays_keep_one_target_and_postgresql_blocks_snapshot_rewrite(): void
    {
        $buyer = Customer::factory()->create();
        $product = $this->starsProduct();
        $intent = $this->starsIntent($buyer, $product, 'same-key', 1);

        $results = $this->runConcurrentWorkers(3, fn (): string => (string) app(OrderCheckoutService::class)
            ->checkoutTelegramStarsDirect($product, 1, $intent)->id, 'stars-replay');
        $this->assertWorkersSucceeded($results);
        $this->assertCount(1, array_unique(array_column($results, 'value')));
        $this->assertOneReservation();

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('payment_telegram_stars_details')->update(['total_xtr' => 51]),
            'payment_stars_snapshot_immutable',
        );
        $target = PaymentTelegramStarsDetail::sole();
        $this->assertDatabaseConstraintRejects(
            fn () => Payment::whereKey($target->payment_id)->update(['amount' => 51]),
            'payment_stars_snapshot_immutable',
        );
        DB::table('stars_pricing_policies')->update(['issuance_enabled' => false, 'idr_per_usd' => 20_000, 'version' => 2]);
        $replay = app(OrderCheckoutService::class)->checkoutTelegramStarsDirect($product, 1, $intent);
        $this->assertSame($target->payment_id, $replay->payments->sole()->id);
        $this->assertSame($target->invoice_payload, $replay->payments->sole()->telegramStarsDetail->invoice_payload);
        $this->assertSame(50, $target->fresh()->total_xtr);
    }

    private function assertOneReservation(): void
    {
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentTelegramStarsDetail::count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertDatabaseEmpty('supplier_fulfillments');
        $this->assertDatabaseEmpty('telegram_outbound_effects');
    }
}
