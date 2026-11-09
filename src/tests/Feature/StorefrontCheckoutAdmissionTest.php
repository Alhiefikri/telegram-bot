<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontCheckoutAdmission;
use App\Storefront\StorefrontCheckoutLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorefrontCheckoutAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        config()->set('services.storefront.telegram_reservation_floor', 0);
    }

    public function test_separate_buyers_on_a_shared_ip_have_independent_request_budgets(): void
    {
        $this->withoutVite();
        for ($i = 0; $i < 6; $i++) {
            $this->app['session.store']->invalidate();
            $this->withCookie(config('session.cookie'), $this->app['session.store']->getId());
            $this->post(route('storefront.shop.checkout'), [])->assertRedirect();
        }
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('storefront.shop.checkout'), [])->assertRedirect();
        }
        $this->post(route('storefront.shop.checkout'), [])->assertStatus(429);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_rotating_anonymous_customers_cannot_bypass_browser_limit_and_exact_replay_still_works(): void
    {
        $product = Product::factory()->create(['stock' => 10, 'reserved_stock' => 0]);
        $admission = new StorefrontCheckoutAdmission('browser-one', '192.0.2.1');
        $firstCart = $this->cart($product);
        $first = $this->checkout($firstCart, 'first', $admission);
        $second = $this->checkout($this->cart($product), 'second', $admission);
        $thirdCart = $this->cart($product);

        $this->assertSame($first->id, $this->checkout($thirdCart, 'first', $admission)->id);
        try {
            $this->checkout($thirdCart, 'different', $admission);
            $this->fail('A different purchase must be rejected, not redirected to an old invoice.');
        } catch (StorefrontCheckoutLimitReached $exception) {
            $this->assertCount(2, $exception->invoiceUrls);
            $this->assertStringContainsString('/shop/orders/'.$first->id, $exception->invoiceUrls[0]);
            $this->assertStringContainsString('/shop/orders/'.$second->id, $exception->invoiceUrls[1]);
        }
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(2, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(2, DB::table('storefront_checkout_admissions')->count());
    }

    public function test_shared_network_allows_independent_buyers_but_rotating_browsers_eventually_hits_a_higher_ceiling(): void
    {
        $product = Product::factory()->create(['stock' => 30, 'reserved_stock' => 0]);
        for ($i = 0; $i < StorefrontCheckoutAdmission::IP_PENDING_LIMIT; $i++) {
            $this->checkout($this->cart($product), 'network-'.$i, new StorefrontCheckoutAdmission('browser-'.$i, '192.0.2.1'));
        }
        try {
            $this->checkout($this->cart($product), 'over-network', new StorefrontCheckoutAdmission('another-browser', '192.0.2.1'));
            $this->fail('The shared network ceiling must apply across browser changes.');
        } catch (StorefrontCheckoutLimitReached $exception) {
            $this->assertSame([], $exception->invoiceUrls, 'Other buyers invoices must never be disclosed.');
        }
        $this->checkout($this->cart($product), 'other-network', new StorefrontCheckoutAdmission('other-network', '192.0.2.2'));
        $this->assertSame(21, Order::count());
        $this->assertSame(21, $product->fresh()->reserved_stock);
    }

    public function test_deadline_alone_does_not_free_capacity_or_stock_but_authoritative_expiry_does(): void
    {
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0]);
        $admission = new StorefrontCheckoutAdmission('expiry-browser');
        $first = $this->checkout($this->cart($product), 'expiry-first', $admission);
        $this->checkout($this->cart($product), 'expiry-second', $admission);
        $first->forceFill(['expires_at' => now()->subMinute()])->save();
        try {
            $admission->assertCapacity();
            $this->fail('An elapsed timestamp must not release an unresolved reservation.');
        } catch (StorefrontCheckoutLimitReached) {
            $this->assertSame(2, $product->fresh()->reserved_stock);
        }
        app(OrderStateMachine::class)->expirePendingOrder($first);
        $this->assertSame(OrderStatus::Expired, $first->fresh()->order_status);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->checkout($this->cart($product), 'expiry-next', $admission);
        $this->assertSame(2, $product->fresh()->reserved_stock);
    }

    public function test_cancellation_and_paid_completion_each_free_the_pending_slot_through_existing_lifecycle(): void
    {
        $product = Product::factory()->create(['stock' => 6, 'reserved_stock' => 0]);
        $admission = new StorefrontCheckoutAdmission('lifecycle-browser');
        $cancel = $this->checkout($this->cart($product), 'cancel', $admission);
        $paid = $this->checkout($this->cart($product), 'paid', $admission);
        app(OrderStateMachine::class)->cancelPendingOrder($cancel);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->checkout($this->cart($product), 'after-cancel', $admission);
        app(OrderStateMachine::class)->completeManualOrder($paid);
        $this->assertSame(OrderStatus::Completed, $paid->fresh()->order_status);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->checkout($this->cart($product), 'after-paid', $admission);
        $this->assertSame(4, Order::count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
    }

    public function test_failed_order_transaction_does_not_consume_a_pending_slot(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cart($product);
        $product->forceFill(['stock' => 0])->save();
        try {
            $this->checkout($cart, 'no-stock', new StorefrontCheckoutAdmission('rollback-browser'));
            $this->fail('Stock revalidation must reject the purchase.');
        } catch (InsufficientStock) {
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, DB::table('storefront_checkout_admissions')->count());
        }
    }

    private function cart(Product $product): Cart
    {
        $cart = app(CartService::class)->activeCartFor(Customer::factory()->create(['telegram_id' => null]));
        app(CartService::class)->setItemQuantity($cart, $product->fresh(), 1);

        return $cart;
    }

    private function checkout(Cart $cart, string $key, StorefrontCheckoutAdmission $admission): Order
    {
        return app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::manualQris(),
            checkoutKey: hash('sha256', $key),
            storefrontAdmission: $admission,
        );
    }
}
