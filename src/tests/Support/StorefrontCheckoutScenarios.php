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
        string $buyerName,
        string $paymentNumber,
        int $fee = 103,
        ?string $supportContactChannel = null,
        ?string $supportContactValue = null,
    ): array {
        $this->pakasirNativeQrisIsReady();
        $this->qrisRendererReturnsDeterministicPng();
        $this->providerQrisCreateSucceedsWithProviderTotal(
            amount: $product->price_amount * $quantity,
            paymentNumber: $paymentNumber,
            fee: $fee,
        );

        $response = $this->postStorefrontShopCheckout($product, $quantity, $buyerName, $supportContactChannel, $supportContactValue);

        $order = Order::with(['customer', 'cart', 'payments', 'orderItems.stockItems'])->firstOrFail();
        $payment = $order->payments->first();
        $this->assertInstanceOf(Payment::class, $payment);

        return [$response, $order, $payment];
    }

    protected function postStorefrontShopCheckout(
        Product $product,
        int $quantity,
        string $buyerName,
        ?string $supportContactChannel = null,
        ?string $supportContactValue = null,
    ): TestResponse {
        $payload = [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'buyer_name' => $buyerName,
        ];

        if ($supportContactChannel !== null || $supportContactValue !== null) {
            $payload['support_contact_channel'] = $supportContactChannel;
            $payload['support_contact_value'] = $supportContactValue;
        }

        return $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), $payload);
    }
}
