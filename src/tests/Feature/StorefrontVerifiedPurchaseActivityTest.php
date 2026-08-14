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

    public function test_it_projects_paid_processable_orders_with_matching_inventory_sale_proof_without_sensitive_order_data(): void
    {
        $telegramOrder = $this->orderWithItem('Alya', 1234, OrderStatus::Completed, PaymentStatus::Paid, true);
        $websiteOrder = $this->orderWithItem('Budi', null, OrderStatus::Processing, PaymentStatus::Paid, true);
        $this->orderWithItem('Pending', null, OrderStatus::PendingPayment, PaymentStatus::Pending, true);
        $this->orderWithItem('Cancelled', null, OrderStatus::Cancelled, PaymentStatus::Paid, true);
        $this->orderWithItem('No proof', null, OrderStatus::Completed, PaymentStatus::Paid, false);

        $items = $this->getJson(route('storefront.verified-purchase-activity'))
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->json('items');

        foreach ($items as $item) {
            $this->assertSame(['id', 'buyer', 'product', 'occurred_at', 'relative_time'], array_keys($item));
        }
        $this->assertEqualsCanonicalizing(['A***', 'B***'], array_column($items, 'buyer'));
        $this->assertSame(['Produk aman', 'Produk aman'], array_column($items, 'product'));
        $this->assertNotContains($telegramOrder->invoice_number, $items);
        $this->assertNotContains($websiteOrder->invoice_number, $items);
        $this->assertNotContains(1234, $items);
    }

    public function test_it_rejects_mismatched_sale_proof_for_a_website_order(): void
    {
        $order = $this->orderWithItem('Budi', null, OrderStatus::Completed, PaymentStatus::Paid, true, 2);
        InventoryMovement::query()->where('order_id', $order->id)->update(['quantity' => -1]);
        Cache::forget('storefront.verified-purchase-activity.v1');

        $this->getJson(route('storefront.verified-purchase-activity'))
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_it_bounds_the_public_history(): void
    {
        foreach (range(1, 9) as $number) {
            $this->orderWithItem('Buyer '.$number, null, OrderStatus::Processing, PaymentStatus::Paid, true);
        }
        Cache::forget('storefront.verified-purchase-activity.v1');

        $this->getJson(route('storefront.verified-purchase-activity'))
            ->assertOk()
            ->assertJsonCount(8, 'items');
    }

    public function test_it_uses_a_bounded_cacheable_rate_limited_public_read_endpoint(): void
    {
        for ($request = 0; $request < 12; $request++) {
            $this->getJson(route('storefront.verified-purchase-activity'))
                ->assertOk()
                ->assertHeader('Cache-Control', 'max-age=45, public');
        }

        $this->getJson(route('storefront.verified-purchase-activity'))
            ->assertTooManyRequests();
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
