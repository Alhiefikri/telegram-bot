<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Testing\TestResponse;

trait StorefrontCheckoutScenarios
{
    use PakasirNativeQrisScenarios;
    use StorefrontProductScenarios;

    protected function successfulNativeQrisCheckout(
        Product $product,
        int $quantity,
        string $buyerHandle,
        string $paymentNumber,
        int $fee = 103,
    ): array {
        $this->pakasirNativeQrisIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        $this->providerQrisCreateSucceedsWithProviderTotal(
            amount: $product->price_amount * $quantity,
            paymentNumber: $paymentNumber,
            fee: $fee,
        );

        $response = $this->postStorefrontShopCheckout($product, $quantity, $buyerHandle);

        $order = Order::with(['customer', 'cart', 'payments', 'orderItems.stockItems'])->firstOrFail();
        $payment = $order->payments->first();
        $this->assertInstanceOf(Payment::class, $payment);

        return [$response, $order, $payment];
    }

    protected function postStorefrontShopCheckout(Product $product, int $quantity, string $buyerHandle): TestResponse
    {
        return $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'buyer_handle' => $buyerHandle,
            ]);
    }
}
