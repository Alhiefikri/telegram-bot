<?php

namespace Tests;

use App\Models\Cart;
use App\Models\Order;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    /**
     * Build a historical Pakasir Payment without routing a new checkout
     * request through the retired provider.
     */
    protected function legacyPakasirOrder(Cart $cart, string $flow = PakasirCheckoutFlow::HOSTED): Order
    {
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $payment = $order->payments()->sole();
        $payment->forceFill([
            'provider' => PaymentProvider::PAKASIR,
            'method' => $flow,
            'provider_payment_method' => $flow === PakasirCheckoutFlow::API_QRIS ? 'qris' : null,
        ])->save();

        return $order->fresh(['payments']);
    }
}
