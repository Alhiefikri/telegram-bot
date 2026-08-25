<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface CheckoutPaymentIntent extends CheckoutPaymentPreparation
{
    public function provider(): string;

    public function method(): string;

    public function orderCurrency(): string;

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    public function paymentAmount(Collection $items, Collection $products): int;

    public function paymentCurrency(): string;

    public function expiresAt(
        ?CarbonInterface $requested = null,
        ?ManualPaymentSetting $setting = null,
    ): CarbonInterface;

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    public function assertReady(
        Cart $cart,
        Collection $items,
        Collection $products,
        ?ManualPaymentSetting $setting = null,
    ): void;

    public function postCreationBehavior(): CheckoutPostCreationBehavior;
}
