<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckoutMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_activate_and_deactivate_checkout_maintenance_with_audited_versioning(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $activation = $this->actingAs($owner)->post(route('admin.checkout-maintenance.update'), [
            'active' => true,
            'confirmed' => true,
            'version' => 0,
            'public_note' => 'Checkout dibuka kembali setelah sinkronisasi.',
        ]);

        $activation->assertRedirect();
        $activation->assertSessionHas('status');
        $this->assertDatabaseHas('checkout_maintenance_settings', [
            'singleton_key' => CheckoutMaintenanceSetting::SINGLETON_KEY,
            'is_active' => true,
            'public_note' => 'Checkout dibuka kembali setelah sinkronisasi.',
            'version' => 1,
        ]);
        $this->assertSame('checkout_maintenance.activated', AuditLog::query()->latest('id')->value('action'));

        $deactivation = $this->actingAs($owner)->post(route('admin.checkout-maintenance.update'), [
            'active' => false,
            'confirmed' => true,
            'version' => 1,
            'public_note' => 'Catatan ini harus dihapus.',
        ]);

        $deactivation->assertRedirect();
        $this->assertDatabaseHas('checkout_maintenance_settings', [
            'singleton_key' => CheckoutMaintenanceSetting::SINGLETON_KEY,
            'is_active' => false,
            'public_note' => null,
            'version' => 2,
            'activated_at' => null,
        ]);
        $this->assertSame('checkout_maintenance.deactivated', AuditLog::query()->latest('id')->value('action'));
    }

    public function test_stale_admin_version_is_rejected_without_mutating_state(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = CheckoutMaintenanceSetting::query()->firstOrFail();
        $activatedAt = now()->subHour();
        $setting->forceFill(['is_active' => true, 'public_note' => 'Current note.', 'version' => 4, 'activated_at' => $activatedAt])->save();

        $response = $this->actingAs($owner)->from(route('admin.dashboard', ['tab' => 'stats']))->post(route('admin.checkout-maintenance.update'), [
            'active' => false,
            'confirmed' => true,
            'version' => 3,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrorsIn('checkoutMaintenance', ['active']);
        $this->assertDatabaseHas('checkout_maintenance_settings', [
            'is_active' => true,
            'public_note' => 'Current note.',
            'version' => 4,
        ]);
        $this->assertSame($activatedAt->format('Y-m-d H:i:s'), $setting->fresh()->activated_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'checkout_maintenance.%')->count());
    }

    public function test_admin_mutation_requires_explicit_confirmation(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $response = $this->actingAs($owner)
            ->from(route('admin.dashboard', ['tab' => 'stats']))
            ->post(route('admin.checkout-maintenance.update'), [
                'active' => true,
                'version' => 0,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrorsIn('checkoutMaintenance', ['confirmed']);
        $this->assertDatabaseHas('checkout_maintenance_settings', ['is_active' => false, 'version' => 0]);
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'checkout_maintenance.%')->count());
    }

    public function test_admin_dashboard_renders_checkout_maintenance_control(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->get(route('admin.dashboard', ['tab' => 'stats']))
            ->assertOk()
            ->assertSee('Checkout Maintenance')
            ->assertSee('CHECKOUT TERBUKA')
            ->assertSee(route('admin.checkout-maintenance.update'));
    }

    public function test_finance_and_support_cannot_mutate_checkout_maintenance(): void
    {
        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->post(route('admin.checkout-maintenance.update'), [
                    'confirmed' => true,
                    'active' => true,
                    'version' => 0,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('checkout_maintenance_settings', ['is_active' => false, 'version' => 0]);
    }

    public function test_active_maintenance_blocks_new_web_checkout_before_order_creation(): void
    {
        Http::preventStrayRequests();
        Queue::fake([SendAdminOrderPendingNotification::class]);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => null,
            'version' => 1,
            'activated_at' => now(),
        ]);
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0]);

        $response = $this->from(route('storefront.products.show', $product))->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Web Buyer',
            'payment_method' => 'qris',
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('storefront_checkout_error', 'Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Cart::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, ProductStockItem::query()->count());
        Queue::assertNotPushed(SendAdminOrderPendingNotification::class);
        Http::assertNothingSent();
    }

    public function test_active_maintenance_replaces_storefront_purchase_cta_but_keeps_catalog_access(): void
    {
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Admin sedang memperbarui payment provider.',
            'version' => 1,
            'activated_at' => now(),
        ]);
        $product = Product::factory()->create(['name' => 'Maintenance Product', 'stock' => 4, 'reserved_stock' => 0]);

        $response = $this->get(route('storefront.products.show', $product));

        $response->assertOk()
            ->assertSee('Checkout sedang maintenance')
            ->assertSee('Admin sedang memperbarui payment provider.')
            ->assertDontSee('Beli via Web QRIS')
            ->assertSee('Akses Pesanan');
    }

    public function test_existing_order_idempotency_wins_over_active_maintenance_guard(): void
    {
        CheckoutMaintenanceSetting::query()->firstOrFail()->update(['is_active' => true, 'version' => 1, 'activated_at' => now()]);
        $order = Order::factory()->create([
            'checkout_key' => 'existing-checkout-key',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $cart = $order->customer->carts()->create(['status' => 'active', 'expires_at' => now()->addHour()]);

        $result = app(OrderCheckoutService::class)->checkout(
            $cart,
            checkoutKey: 'existing-checkout-key',
        );

        $this->assertTrue($result->is($order));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_existing_checked_out_cart_order_reuse_wins_over_active_maintenance_guard(): void
    {
        CheckoutMaintenanceSetting::query()->firstOrFail()->update(['is_active' => true, 'version' => 1, 'activated_at' => now()]);
        $customer = Customer::factory()->create();
        $cart = $customer->carts()->create([
            'status' => CartStatus::CheckedOut,
            'expires_at' => now()->addHour(),
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'cart_id' => $cart->id,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $result = app(OrderCheckoutService::class)->checkout($cart);

        $this->assertTrue($result->is($order));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(CartStatus::CheckedOut, $cart->fresh()->status);
    }

    public function test_existing_orders_can_cancel_and_expire_during_maintenance(): void
    {
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $firstCustomer = Customer::factory()->create();
        $firstCart = app(CartService::class)->activeCartFor($firstCustomer);
        app(CartService::class)->setItemQuantity($firstCart, $product, 1);
        $cancelOrder = app(OrderCheckoutService::class)->checkout($firstCart, CheckoutPaymentIntents::manualQris());

        $secondProduct = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $secondCustomer = Customer::factory()->create();
        $secondCart = app(CartService::class)->activeCartFor($secondCustomer);
        app(CartService::class)->setItemQuantity($secondCart, $secondProduct, 1);
        $expireOrder = app(OrderCheckoutService::class)->checkout($secondCart, CheckoutPaymentIntents::manualQris());

        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $cancelled = app(OrderLifecycle::class)->applyPendingCancellation($cancelOrder);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->order->order_status);
        $this->assertSame(PaymentStatus::Failed, $cancelled->payment->status);

        $expireOrder->update(['expires_at' => now()->subMinute()]);
        $expired = app(OrderLifecycle::class)->applyPendingExpiry($expireOrder->fresh());
        $this->assertSame(OrderStatus::Expired, $expired->order->order_status);
        $this->assertSame(PaymentStatus::Expired, $expired->payment->status);
        $this->assertTrue((bool) CheckoutMaintenanceSetting::query()->firstOrFail()->is_active);
    }

    public function test_maintenance_module_returns_noop_for_same_state_without_audit_increment(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $maintenance = app(CheckoutMaintenance::class);

        $first = $maintenance->change(true, 'Temporarily unavailable.', 0, $owner, '127.0.0.1');
        $second = $maintenance->change(true, 'Temporarily unavailable.', $first->newState->version, $owner, '127.0.0.1');

        $this->assertTrue($first->changed);
        $this->assertFalse($second->changed);
        $this->assertSame(1, $second->newState->version);
        $this->assertSame(1, AuditLog::query()->where('action', 'like', 'checkout_maintenance.%')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'checkout_maintenance.activated')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'checkout_maintenance.updated')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'checkout_maintenance.deactivated')->count());
    }
}
