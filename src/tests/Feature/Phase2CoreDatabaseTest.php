<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase2CoreDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_and_key_columns_exist(): void
    {
        foreach (['customers', 'products', 'carts', 'cart_items', 'orders', 'order_items', 'payments', 'payment_events', 'telegram_updates', 'inventory_movements', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertTrue(Schema::hasColumns('payment_events', ['merchant_ref', 'provider_status', 'raw_payload', 'received_at']));
        $this->assertTrue(Schema::hasColumns('orders', ['id', 'invoice_number', 'order_status', 'payment_status']));
        $this->assertTrue(Schema::hasColumns('payments', ['id', 'merchant_ref', 'provider_reference', 'status']));
    }

    public function test_uuid_models_persist_with_uuid_primary_keys(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::factory()->create(['order_id' => $order->id]);

        $this->assertTrue(Str::isUuid($order->id));
        $this->assertTrue(Str::isUuid($payment->id));
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_json_casts_round_trip(): void
    {
        $event = PaymentEvent::factory()->create(['raw_payload' => ['nested' => ['status' => 'PAID']]]);
        $update = TelegramUpdate::factory()->create(['payload' => ['message' => ['text' => '/start']]]);
        $audit = AuditLog::factory()->create(['old_values' => ['stock' => 1], 'new_values' => ['stock' => 2]]);

        $this->assertSame('PAID', $event->fresh()->raw_payload['nested']['status']);
        $this->assertSame('/start', $update->fresh()->payload['message']['text']);
        $this->assertSame(2, $audit->fresh()->new_values['stock']);
    }

    public function test_product_soft_delete(): void
    {
        $product = Product::factory()->create();

        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_core_relationships_and_enum_casts(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active]);
        $cartItem = CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Pending]);
        $event = PaymentEvent::factory()->create(['payment_id' => $payment->id, 'event_type' => PaymentEventType::Callback]);
        $movement = InventoryMovement::factory()->create(['product_id' => $product->id, 'order_id' => $order->id, 'type' => InventoryMovementType::Reservation]);
        $update = TelegramUpdate::factory()->create(['customer_id' => $customer->id]);
        $user = User::factory()->create();
        $audit = AuditLog::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($customer->carts->contains($cart));
        $this->assertTrue($cart->cartItems->contains($cartItem));
        $this->assertTrue($order->orderItems->contains($orderItem));
        $this->assertTrue($order->payments->contains($payment));
        $this->assertTrue($payment->paymentEvents->contains($event));
        $this->assertTrue($product->inventoryMovements->contains($movement));
        $this->assertTrue($customer->telegramUpdates->contains($update));
        $this->assertSame($user->id, $audit->user->id);
        $this->assertSame(CartStatus::Active, $cart->status);
    }
}
