<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontShopCartTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.storefront.telegram_reservation_floor', 0);
    }

    public function test_guest_can_add_distinct_products_and_review_one_server_priced_subtotal(): void
    {
        $this->manualPaymentModeIsActive();
        $first = $this->availableProduct(['name' => 'Cart First Product', 'price_amount' => 10000, 'stock' => 3]);
        $second = $this->availableProduct(['name' => 'Cart Second Product', 'price_amount' => 15000, 'stock' => 3]);

        $this->addToCart($first, sessionKey: 'cart-subtotal-session');
        $this->addToCart($second, sessionKey: 'cart-subtotal-session');

        $this->withSession(['storefront.checkout_client_key' => 'cart-subtotal-session'])
            ->get(route('storefront.shop.cart'))
            ->assertOk()
            ->assertSee('Cart First Product')
            ->assertSee('Cart Second Product')
            ->assertSee('Subtotal (IDR)')
            ->assertSee('Rp25.000')
            ->assertSee('Stok belum dicadangkan sampai Anda membuat order.');

        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Cart::query()->where('status', CartStatus::Active)->count());
    }

    public function test_quantity_updates_and_removals_use_the_active_guest_cart(): void
    {
        $product = $this->availableProduct(['name' => 'Cart Mutation Product', 'stock' => 5]);
        $sessionKey = 'cart-mutation-session';

        $this->addToCart($product, sessionKey: $sessionKey);
        $customer = Customer::query()->sole();
        $cart = Cart::query()->where('customer_id', $customer->id)->sole();

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->patch(route('storefront.shop.cart.items.update', ['product' => $product]), ['quantity' => 3])
            ->assertRedirect(route('storefront.shop.cart'));

        $this->assertSame(3, CartItem::query()->where('cart_id', $cart->id)->where('product_id', $product->id)->value('quantity'));

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->delete(route('storefront.shop.cart.items.destroy', ['product' => $product]))
            ->assertRedirect(route('storefront.shop.cart'));

        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id, 'product_id' => $product->id]);
    }

    public function test_guest_cart_isolated_by_session_identity(): void
    {
        $first = $this->availableProduct(['name' => 'First Browser Product']);
        $second = $this->availableProduct(['name' => 'Second Browser Product']);

        $this->addToCart($first, sessionKey: 'browser-one');

        $this->withSession(['storefront.checkout_client_key' => 'browser-two'])
            ->get(route('storefront.shop.cart'))
            ->assertOk()
            ->assertSee('Keranjang masih kosong.')
            ->assertDontSee('First Browser Product');

        $this->addToCart($second, sessionKey: 'browser-two');

        $this->assertSame(2, Customer::query()->count());
        $this->assertSame(2, Cart::query()->where('status', CartStatus::Active)->count());
    }

    public function test_expired_guest_cart_cannot_be_updated_and_is_not_reused(): void
    {
        $product = $this->availableProduct(['name' => 'Expiring Cart Product']);
        $sessionKey = 'expiring-cart-session';
        $this->addToCart($product, sessionKey: $sessionKey);
        $cart = Cart::query()->sole();

        $this->travelTo(now()->addDay()->addMinute());
        try {
            $this->withSession(['storefront.checkout_client_key' => $sessionKey])
                ->patch(route('storefront.shop.cart.items.update', ['product' => $product]), ['quantity' => 2])
                ->assertRedirect(route('storefront.shop.cart'))
                ->assertSessionHas('storefront_cart_error', 'Keranjang sudah kedaluwarsa atau belum dibuat.');
        } finally {
            $this->travelBack();
        }

        $this->assertSame(CartStatus::Expired, $cart->fresh()->status);
        $this->assertSame(1, CartItem::query()->where('cart_id', $cart->id)->value('quantity'));
    }

    public function test_multi_item_checkout_creates_one_order_payment_and_reservation_per_item(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $first = $this->availableProduct(['name' => 'Multi First', 'price_amount' => 10000, 'stock' => 3]);
        $second = $this->availableProduct(['name' => 'Multi Second', 'price_amount' => 15000, 'stock' => 3]);
        $sessionKey = 'multi-checkout-session';

        $this->addToCart($first, sessionKey: $sessionKey);
        $this->addToCart($second, sessionKey: $sessionKey);
        $snapshot = $this->checkoutSnapshot($sessionKey);
        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk()
            ->assertSee('Multi First')
            ->assertSee('Multi Second')
            ->assertSee('Rp25.000')
            ->assertSee('name="cart_snapshot"', false)
            ->assertSee('name="payment_method"', false)
            ->assertSee('Kontak bantuan', false);

        $response = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'buyer_name' => 'Multi Buyer',
                'cart_snapshot' => $snapshot,
                'email' => 'multi@example.test',
                'support_contact_channel' => 'telegram',
                'support_contact_value' => '@multi_buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ]);

        $response->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame('@multi_buyer', $order->support_contact_value);
        $this->assertNotNull($order->credential_access_key_hash);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $first->fresh()->reserved_stock);
        $this->assertSame(1, $second->fresh()->reserved_stock);
        $this->assertSame(2, InventoryMovement::query()->where('order_id', $order->id)->count());
        $this->assertSame(CartStatus::CheckedOut, $order->cart()->value('status'));
    }

    public function test_cart_checkout_selects_binance_pay_id_for_all_usdt_products(): void
    {
        Queue::fake();
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $first = $this->availableProduct([
            'name' => 'USDT Cart First',
            'price_amount' => 10000,
            'usdt_price_amount' => 2000000,
        ]);
        $second = $this->availableProduct([
            'name' => 'USDT Cart Second',
            'price_amount' => 15000,
            'usdt_price_amount' => 1500000,
        ]);
        $sessionKey = 'binance-cart-session';
        $this->addToCart($first, sessionKey: $sessionKey);
        $this->addToCart($second, sessionKey: $sessionKey);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk()
            ->assertSee('Bayar dengan Binance Pay');
        $snapshot = $this->checkoutSnapshot($sessionKey);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $snapshot,
                'buyer_name' => 'Binance Cart Buyer',
                'payment_method' => 'binance_pay_id',
                'terms_accepted' => '1',
            ])
            ->assertRedirect();

        $order = Order::query()->sole();
        $payment = $order->payments()->sole();
        $this->assertSame('BINANCE_PAY_ID', $payment->method);
        $this->assertSame('USDT', $payment->currency);
        $this->assertSame(3500000, $payment->amount);
    }

    public function test_repeated_cart_checkout_submit_reuses_one_order_and_payment(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $first = $this->availableProduct(['name' => 'Duplicate First', 'price_amount' => 10000]);
        $second = $this->availableProduct(['name' => 'Duplicate Second', 'price_amount' => 15000]);
        $sessionKey = 'duplicate-cart-session';
        $this->addToCart($first, sessionKey: $sessionKey);
        $this->addToCart($second, sessionKey: $sessionKey);
        $snapshot = $this->checkoutSnapshot($sessionKey);

        $payload = [
            'cart_snapshot' => $snapshot,
            'buyer_name' => 'Duplicate Buyer',
            'payment_method' => 'qris',
            'terms_accepted' => '1',
        ];
        $firstResponse = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), $payload);
        $secondResponse = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), $payload);

        $firstResponse->assertRedirect();
        $secondResponse->assertRedirect($firstResponse->headers->get('Location'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(2, InventoryMovement::query()->where('type', 'reservation')->count());
    }

    public function test_direct_buy_uses_a_new_cart_after_a_previous_order_is_complete(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['name' => 'Direct Buy Again', 'price_amount' => 12000, 'stock' => 3]);
        $sessionKey = 'direct-buy-again-session';
        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Repeat Direct Buyer',
            'payment_method' => 'qris',
            'terms_accepted' => '1',
        ];

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), $payload)
            ->assertRedirect();
        $this->addToCart($product, sessionKey: $sessionKey);
        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), $payload)
            ->assertRedirect();

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
    }

    public function test_stock_conflict_is_visible_and_checkout_has_no_partial_side_effects(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['name' => 'Stock Conflict Product', 'price_amount' => 12000, 'stock' => 2]);
        $sessionKey = 'stock-conflict-session';
        $this->addToCart($product, sessionKey: $sessionKey);
        $snapshot = $this->checkoutSnapshot($sessionKey);
        $product->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.cart'))
            ->assertOk()
            ->assertSee('Perlu diperiksa')
            ->assertSee('Stok produk berubah menjadi 0.');

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $snapshot,
                'buyer_name' => 'Conflict Buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertRedirect(route('storefront.shop.checkout.start'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_checkout_maintenance_rejects_new_cart_order_without_rows(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['name' => 'Maintenance Cart Product']);
        $sessionKey = 'maintenance-cart-session';
        $this->addToCart($product, sessionKey: $sessionKey);
        $snapshot = $this->checkoutSnapshot($sessionKey);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $snapshot,
                'buyer_name' => 'Maintenance Buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertRedirect(route('storefront.shop.checkout.start'))
            ->assertSessionHas('storefront_checkout_error');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_mixed_credential_and_non_credential_products_keep_existing_fulfillment_allocation(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $credentialProduct = $this->availableProductWithCredentials(
            ['mixed@example.test|pass'],
            ['name' => 'Credential Cart Product', 'price_amount' => 20000],
        );
        $plainProduct = $this->availableProduct(['name' => 'Plain Cart Product', 'price_amount' => 5000]);
        $sessionKey = 'mixed-cart-session';
        $this->addToCart($credentialProduct, sessionKey: $sessionKey);
        $this->addToCart($plainProduct, sessionKey: $sessionKey);
        $snapshot = $this->checkoutSnapshot($sessionKey);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $snapshot,
                'buyer_name' => 'Mixed Buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(2, $order->orderItems()->count());
        $this->assertSame(1, ProductStockItem::query()->where('product_id', $credentialProduct->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(1, $credentialProduct->fresh()->reserved_stock);
        $this->assertSame(1, $plainProduct->fresh()->reserved_stock);
    }

    public function test_stale_cart_snapshot_is_rejected_before_order_creation(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct(['name' => 'Snapshot Product', 'price_amount' => 10000]);
        $sessionKey = 'snapshot-cart-session';
        $this->addToCart($product, sessionKey: $sessionKey);

        $review = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk();
        preg_match('/name="cart_snapshot" value="([a-f0-9]+)"/', $review->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $product->update(['price_amount' => 11000]);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $matches[1],
                'buyer_name' => 'Snapshot Buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertRedirect(route('storefront.shop.checkout.start'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_cart_checkout_review_keeps_quantities_read_only_and_links_back_to_cart(): void
    {
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct([
            'name' => 'Read Only Cart Review',
            'price_amount' => 25000,
            'stock' => 3,
        ]);
        $sessionKey = 'read-only-cart-review-session';

        $this->addToCart($product, quantity: 2, sessionKey: $sessionKey);

        $html = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Read Only Cart Review', $html);
        $this->assertStringContainsString('Jumlah 2', $html);
        $this->assertStringContainsString('Ubah jumlah di keranjang', $html);
        $this->assertStringContainsString('name="cart_snapshot"', $html);
        $this->assertStringNotContainsString('id="checkout-quantity"', $html);
        $this->assertStringNotContainsString('name="quantity"', $html);
        $this->assertStringNotContainsString('role="dialog"', $html);
    }

    private function checkoutSnapshot(string $sessionKey): string
    {
        $response = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk();
        preg_match('/name="cart_snapshot" value="([a-f0-9]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }

    private function manualPaymentModeIsActive(): void
    {
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    private function addToCart(Product $product, int $quantity = 1, string $sessionKey = 'guest-cart-session'): void
    {
        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.cart.items.store'), [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ])
            ->assertRedirect(route('storefront.shop.cart'));
    }
}
