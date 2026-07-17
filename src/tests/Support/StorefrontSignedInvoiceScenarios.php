<?php

namespace Tests\Support;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductStockItem;

trait StorefrontSignedInvoiceScenarios
{
    use PakasirNativeQrisScenarios;
    use StorefrontProductScenarios;

    protected function pendingNativeQrisOrder(
        int $totalAmount,
        ?int $providerFee,
        ?int $providerTotalPayment,
        string $paymentNumber,
        ?string $secret = null,
        array $productAttributes = [],
    ): array {
        $product = $this->availableProduct(array_merge([
            'name' => 'Signed Invoice Product',
            'price_amount' => $totalAmount,
            'stock' => 2,
            'reserved_stock' => 1,
        ], $productAttributes));
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create([
                'first_name' => 'Web Buyer',
                'username' => 'webbuyer',
            ])->id,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => $totalAmount,
        ]);
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $totalAmount,
            'subtotal' => $totalAmount,
        ]);
        InventoryMovement::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation,
            'quantity' => 1,
            'notes' => 'Order reservation',
            'created_at' => now(),
        ]);

        if ($secret !== null) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $orderItem->id,
                'secret' => $secret,
            ]);
        }

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-SHOP-TEST-'.$order->id,
            'provider_payment_method' => 'qris',
            'provider_payment_number' => $paymentNumber,
            'provider_payment_number_hash' => hash('sha256', $paymentNumber),
            'provider_fee' => $providerFee,
            'provider_total_payment' => $providerTotalPayment,
            'method' => 'api_qris',
            'amount' => $totalAmount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addMinutes(15),
        ]);

        return [$order->fresh(['orderItems.stockItems', 'payments']), $payment->fresh()];
    }

    protected function paidOrderWithAccountSecret(
        OrderStatus $orderStatus,
        string $secret,
        string $productName = 'Secret Product',
        int $totalAmount = 99000,
    ): array {
        return $this->paidOrderWithAccountSecretGroups([
            [
                'product_name' => $productName,
                'unit_price' => $totalAmount,
                'secrets' => [$secret],
            ],
        ], $orderStatus);
    }

    protected function paidOrderWithAccountSecretGroups(
        array $groups,
        OrderStatus $orderStatus = OrderStatus::Processing,
    ): array {
        $totalAmount = collect($groups)->sum(
            fn (array $group): int => ($group['unit_price'] ?? 99000) * count($group['secrets'])
        );
        $order = Order::factory()->create([
            'order_status' => $orderStatus,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => $totalAmount,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'merchant_ref' => 'INV-SHOP-TEST-PAID-'.$order->id,
            'provider_payment_method' => 'qris',
            'amount' => $totalAmount,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        foreach ($groups as $group) {
            $secrets = $group['secrets'];
            $unitPrice = $group['unit_price'] ?? 99000;
            $product = $this->availableProduct([
                'name' => $group['product_name'],
                'price_amount' => $unitPrice,
                'stock' => count($secrets),
                'reserved_stock' => count($secrets),
            ]);
            $item = OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => count($secrets),
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * count($secrets),
            ]);

            foreach ($secrets as $secret) {
                ProductStockItem::create([
                    'product_id' => $product->id,
                    'order_item_id' => $item->id,
                    'secret' => $secret,
                ]);
            }
        }

        return [$order->fresh(['orderItems.stockItems', 'payments'])];
    }
}
