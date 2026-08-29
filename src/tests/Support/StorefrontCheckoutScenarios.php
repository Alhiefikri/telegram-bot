<?php

namespace Tests\Support;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait StorefrontCheckoutScenarios
{
    use StorefrontProductScenarios;

    protected function manualPaymentModeIsActive(): void
    {
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    protected function successfulNativeQrisCheckout(
        Product $product,
        int $quantity,
        string $buyerName,
        ?string $supportContactChannel = null,
        ?string $supportContactValue = null,
        ?string $email = null,
    ): array {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'klikqris',
            'is_active' => true,
        ]);
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/create' => function (Request $request) {
                $payment = new Payment([
                    'merchant_ref' => (string) $request['order_id'],
                    'amount' => (int) $request['amount'],
                ]);

                return Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]));
            },
        ]);

        $response = $this->postStorefrontShopCheckout($product, $quantity, $buyerName, $supportContactChannel, $supportContactValue, $email);

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
        ?string $email = null,
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
        if ($email !== null) {
            $payload['email'] = $email;
        }

        return $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), $payload);
    }
}
