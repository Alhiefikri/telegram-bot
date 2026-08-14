<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StorefrontVerifiedPurchaseActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_only_paid_orders_with_matching_inventory_sale_proof(): void
    {
        $eligible = $this->orderWithItem('Alya', 1234, OrderStatus::Completed, PaymentStatus::Paid, true);
        $this->orderWithItem('Pending', null, OrderStatus::PendingPayment, PaymentStatus::Pending, true);
        $this->orderWithItem('No proof', null, OrderStatus::Completed, PaymentStatus::Paid, false);

        $response = $this->getJson(route('storefront.verified-purchase-activity'));

        $response->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.buyer', 'A***')
            ->assertJsonPath('items.0.product', 'Produk aman')
            ->assertJsonPath('items.0.amount', 'Rp10.000')
            ->assertJsonPath('items.0.origin', 'Telegram')
            ->assertJsonMissing(['invoice_number' => $eligible->invoice_number])
            ->assertJsonMissing(['telegram_id' => 1234]);
    }

    public function test_it_rejects_mismatched_sale_proof_and_uses_website_origin_for_guest_orders(): void
    {
        $order = $this->orderWithItem('Budi', null, OrderStatus::Completed, PaymentStatus::Paid, true, 2);
        InventoryMovement::query()->where('order_id', $order->id)->update(['quantity' => -1]);
        Cache::forget('storefront.verified-purchase-activity.v1');

        $this->getJson(route('storefront.verified-purchase-activity'))
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    private function orderWithItem(string $buyer, ?int $telegramId, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $saleProof, int $quantity = 1): Order
    {
        $customer = Customer::factory()->create(['first_name' => $buyer, 'telegram_id' => $telegramId]);
        $product = Product::factory()->create(['name' => 'Produk aman']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'total_amount' => 10000,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity]);
        if ($saleProof) {
            InventoryMovement::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'type' => InventoryMovementType::Sale,
                'quantity' => -$quantity,
            ]);
        }

        return $order;
    }
}
