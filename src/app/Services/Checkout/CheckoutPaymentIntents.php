<?php

namespace App\Services\Checkout;

use App\Support\PakasirCheckoutFlow;
use Carbon\CarbonInterface;

final class CheckoutPaymentIntents
{
    public static function manualQris(?CarbonInterface $deadline = null): ManualQrisCheckoutIntent
    {
        return new ManualQrisCheckoutIntent($deadline);
    }

    public static function binancePay(?CarbonInterface $deadline = null): BinancePayCheckoutIntent
    {
        return new BinancePayCheckoutIntent($deadline);
    }

    public static function pakasir(string $checkoutFlow = PakasirCheckoutFlow::HOSTED, ?CarbonInterface $deadline = null): PakasirCheckoutIntent
    {
        return new PakasirCheckoutIntent($checkoutFlow, $deadline);
    }

    public static function klikQris(?CarbonInterface $deadline = null): KlikQrisCheckoutIntent
    {
        return new KlikQrisCheckoutIntent($deadline);
    }
}
