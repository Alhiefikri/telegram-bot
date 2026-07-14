<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStock;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\ProductStockItemManager;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class ProductInventoryRaceTest extends TelegramBotTestSupport
{
    public function test_two_buyers_competing_for_last_stock_can_cart_but_only_one_checkout_reserves_stock(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 1, secrets: ['last-secret']);

        $this->postTelegramWebhook($this->callbackUpdate(39001, 'buyer-a-qty', 'qty:'.$product->id.':1', ['id' => 390001, 'username' => 'buyer_a'], 390001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39002, 'buyer-b-qty', 'qty:'.$product->id.':1', ['id' => 390002, 'username' => 'buyer_b'], 390002))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39003, 'buyer-a-checkout', 'checkout', ['id' => 390001, 'username' => 'buyer_a'], 390001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39004, 'buyer-b-checkout', 'checkout', ['id' => 390002, 'username' => 'buyer_b'], 390002))->assertOk();

        $this->assertSame(2, CartItem::sum('quantity'));
        $this->assertSame(1, Cart::where('status', CartStatus::CheckedOut)->count());
        $this->assertSame(1, Cart::where('status', CartStatus::Active)->count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertStockUnavailableMessageCount(0);
        $this->assertCheckoutStockChangedMessageCount(1);
        $this->assertInventoryInvariants($product);
    }

    public function test_duplicate_quantity_callback_sets_quantity_once_and_does_not_repeat_payment_choices(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 2, secrets: ['secret-a', 'secret-b']);

        $this->postTelegramWebhook($this->callbackUpdate(39003, 'same-buyer-qty-a', 'qty:'.$product->id.':1', ['id' => 390003, 'username' => 'buyer_dup'], 390003))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39004, 'same-buyer-qty-b', 'qty:'.$product->id.':1', ['id' => 390003, 'username' => 'buyer_dup'], 390003))->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(1, CartItem::firstOrFail()->quantity);
        $this->assertPaymentChoiceMessageCount(1);
        $this->assertInventoryInvariants($product);
    }

    public function test_competing_quantity_callbacks_may_exceed_stock_but_checkout_does_not_oversell(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 2, secrets: ['secret-1', 'secret-2']);

        $this->postTelegramWebhook($this->callbackUpdate(39101, 'buyer-a-qty-two', 'qty:'.$product->id.':2', ['id' => 391001, 'username' => 'buyer_a'], 391001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39102, 'buyer-b-qty-one', 'qty:'.$product->id.':1', ['id' => 391002, 'username' => 'buyer_b'], 391002))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39103, 'buyer-a-checkout', 'checkout', ['id' => 391001, 'username' => 'buyer_a'], 391001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39104, 'buyer-b-checkout', 'checkout', ['id' => 391002, 'username' => 'buyer_b'], 391002))->assertOk();

        $this->assertSame(3, CartItem::sum('quantity'));
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertStockUnavailableMessageCount(0);
        $this->assertCheckoutStockChangedMessageCount(1);
        $this->assertInventoryInvariants($product);
    }

    public function test_paused_product_rejects_buy_and_quantity_callbacks_without_cart_mutation(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 1, secrets: ['paused-secret']);
        $product->forceFill(['is_active' => false])->save();

        $this->postTelegramWebhook($this->callbackUpdate(39201, 'paused-buy', 'buy:'.$product->id, ['id' => 392001], 392001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39202, 'paused-qty', 'qty:'.$product->id.':1', ['id' => 392001], 392001))->assertOk();

        $this->assertSame(0, Cart::count());
        $this->assertSame(0, CartItem::count());
        $this->assertInventoryInvariants($product);
    }

    public function test_duplicate_checkout_callback_keeps_single_order_and_single_credential_claim(): void
    {
        $this->fakeTelegramHttp();
        $product = $this->stockedProduct(stock: 1, secrets: ['checkout-secret']);

        $this->postTelegramWebhook($this->callbackUpdate(39301, 'qty-valid', 'qty:'.$product->id.':1', ['id' => 393001], 393001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39302, 'checkout-first', 'checkout', ['id' => 393001], 393001))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(39303, 'checkout-duplicate', 'checkout', ['id' => 393001], 393001))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_stock_ready_notification_requires_active_product_with_available_stock_and_hides_secrets(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 399001]);
        $product = $this->stockedProduct(stock: 1, reservedStock: 1, secrets: ['notify-secret']);

        app(TelegramProductNotificationService::class)->notifyStockAdded($product, 1);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '✅ Stok Tersedia'));

        $product->forceFill(['reserved_stock' => 0])->save();
        app(TelegramProductNotificationService::class)->notifyStockAdded($product, 1);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '✅ Stok Tersedia')
            && str_contains((string) json_encode($request->data()), 'product:'.$product->id)
            && ! str_contains((string) $request['text'], 'notify-secret')
            && ! str_contains((string) json_encode($request->data()), 'notify-secret'));
        $this->assertInventoryInvariants($product);
    }

    public function test_managed_product_availability_uses_lower_numeric_stock_or_unclaimed_credentials(): void
    {
        $numericHigher = $this->stockedProduct(stock: 5, secrets: ['credential-a', 'credential-b']);
        $credentialsHigher = $this->stockedProduct(stock: 1, secrets: ['credential-c', 'credential-d', 'credential-e']);

        $this->assertSame(2, $numericHigher->fresh()->availableStock());
        $this->assertSame(1, $credentialsHigher->fresh()->availableStock());
    }

    public function test_cart_rejects_managed_product_when_numeric_stock_exceeds_credentials(): void
    {
        $product = $this->stockedProduct(stock: 2, secrets: ['only-credential']);
        $firstCustomer = Customer::factory()->create();
        $secondCustomer = Customer::factory()->create();

        $firstCart = app(CartService::class)->activeCartFor($firstCustomer);
        app(CartService::class)->setItemQuantity($firstCart, $product, 1);
        app(OrderCheckoutService::class)->checkout($firstCart);

        $secondCart = app(CartService::class)->activeCartFor($secondCustomer);

        $this->expectException(InsufficientStock::class);
        app(CartService::class)->setItemQuantity($secondCart, $product->fresh(), 1);
    }

    public function test_managed_stock_adjustment_requires_matching_credential_rows(): void
    {
        $product = $this->stockedProduct(stock: 1, secrets: ['managed-credential']);

        $this->expectException(\InvalidArgumentException::class);
        app(StockAdjustmentService::class)->adjust($product, 1, 'numeric-only mismatch');
    }

    public function test_admin_adds_stock_twice_without_losing_submitted_stock_lines(): void
    {
        $product = $this->stockedProduct(stock: 0, secrets: []);
        $admin = User::factory()->create();
        $service = app(StockAdjustmentService::class);

        $service->adjust($product, 2, 'first add', $admin, '127.0.0.1', ['a-1', 'a-2']);
        $service->adjust($product->fresh(), 2, 'second add', $admin, '127.0.0.1', ['b-1', 'b-2']);

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(4, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(4, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->sum('quantity'));
        $this->assertSame(2, AuditLog::where('action', 'product.stock_adjusted')->where('subject_id', (string) $product->id)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_admin_deleting_different_available_stock_items_decrements_once_per_item(): void
    {
        $product = $this->stockedProduct(stock: 2, secrets: ['delete-a', 'delete-b']);
        $admin = User::factory()->create();
        $items = ProductStockItem::where('product_id', $product->id)->orderBy('id')->get();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($product, $items[0], $admin, '127.0.0.1');
        $manager->deleteAvailable($product->fresh(), $items[1], $admin, '127.0.0.1');

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(-2, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->sum('quantity'));
        $this->assertInventoryInvariants($product);
    }

    public function test_admin_deleting_same_stock_item_decrements_and_audits_once(): void
    {
        $product = $this->stockedProduct(stock: 1, secrets: ['delete-once']);
        $admin = User::factory()->create();
        $item = ProductStockItem::where('product_id', $product->id)->firstOrFail();
        $manager = app(ProductStockItemManager::class);

        $manager->deleteAvailable($product, $item, $admin, '127.0.0.1');

        try {
            $manager->deleteAvailable($product->fresh(), $item, $admin, '127.0.0.1');
            $this->fail('Second delete should not succeed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(1, AuditLog::where('action', 'product_stock_item.deleted')->where('subject_id', (string) $product->id)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_buyer_claim_winning_before_admin_delete_or_edit_leaves_single_claimed_stock_item(): void
    {
        $product = $this->stockedProduct(stock: 1, secrets: ['claimed-secret']);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        app(OrderCheckoutService::class)->checkout($cart);
        $admin = User::factory()->create();
        $claimedItem = ProductStockItem::where('product_id', $product->id)->firstOrFail();
        $manager = app(ProductStockItemManager::class);

        foreach (['delete', 'edit'] as $operation) {
            try {
                if ($operation === 'delete') {
                    $manager->deleteAvailable($product->fresh(), $claimedItem, $admin, '127.0.0.1');
                } else {
                    $manager->updateAvailableSecret($product->fresh(), $claimedItem, 'changed-secret', $admin, '127.0.0.1');
                }
                $this->fail("Claimed stock item {$operation} should not succeed.");
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertInventoryInvariants($product);
    }

    public function test_order_release_serializes_with_admin_stock_mutations_and_unclaims_credentials(): void
    {
        $product = $this->stockedProduct(stock: 2, secrets: ['claimed-secret', 'available-secret']);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart);
        $admin = User::factory()->create();
        $availableItem = ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->firstOrFail();

        app(ProductStockItemManager::class)->deleteAvailable($product->fresh(), $availableItem, $admin, '127.0.0.1');
        app(OrderStateMachine::class)->cancelPendingOrder($order->fresh());
        app(StockAdjustmentService::class)->adjust($product->fresh(), 1, 'restock after release', $admin, '127.0.0.1', ['new-secret']);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertInventoryInvariants($product);
    }

    /** @param list<string> $secrets */
    private function stockedProduct(int $stock, array $secrets, int $reservedStock = 0): Product
    {
        $product = Product::factory()->create([
            'stock' => $stock,
            'reserved_stock' => $reservedStock,
            'is_active' => true,
            'price_amount' => 15000,
        ]);

        foreach ($secrets as $secret) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => $secret]);
        }

        return $product;
    }

    private function assertInventoryInvariants(Product $product): void
    {
        $product = $product->fresh();
        $this->assertNotNull($product);
        $numericAvailable = max(0, $product->stock - $product->reserved_stock);

        $this->assertGreaterThanOrEqual(0, $product->stock);
        $this->assertGreaterThanOrEqual(0, $product->reserved_stock);
        $this->assertGreaterThanOrEqual($product->reserved_stock, $product->stock);

        $stockItems = ProductStockItem::where('product_id', $product->id)->get();
        $expectedAvailable = $stockItems->isNotEmpty()
            ? min($numericAvailable, $stockItems->whereNull('order_item_id')->count())
            : $numericAvailable;
        $this->assertSame($expectedAvailable, $product->availableStock());
        $this->assertSame(0, $stockItems->whereNotNull('order_item_id')->filter(fn (ProductStockItem $item): bool => $item->order_item_id === null)->count());
        $this->assertSame(0, $stockItems->whereNull('order_item_id')->filter(fn (ProductStockItem $item): bool => $item->order_item_id !== null)->count());

        $claimedSecrets = $stockItems->whereNotNull('order_item_id')->pluck('secret')->all();
        $this->assertSame(count($claimedSecrets), count(array_unique($claimedSecrets)));
    }

    private function assertPaymentChoiceMessageCount(int $expected): void
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count): bool {
            if (str_ends_with($request->url(), '/sendMessage') && str_contains((string) $request['text'], 'Sudah cocok? Lanjutkan checkout di bawah.')) {
                $count++;
            }

            return true;
        });
        $this->assertSame($expected, $count);
    }

    private function assertStockUnavailableMessageCount(int $expected): void
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count): bool {
            if (str_ends_with($request->url(), '/sendMessage') && str_contains((string) $request['text'], 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.')) {
                $count++;
            }

            return true;
        });
        $this->assertSame($expected, $count);
    }

    private function assertCheckoutStockChangedMessageCount(int $expected): void
    {
        $count = 0;
        Http::assertSent(function ($request) use (&$count): bool {
            if (str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'Checkout belum berhasil.')
                && str_contains((string) $request['text'], 'stok berubah')) {
                $count++;
            }

            return true;
        });
        $this->assertSame($expected, $count);
    }
}
