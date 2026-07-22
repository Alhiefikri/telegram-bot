<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface CheckoutPaymentIntent
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

    public function expiresAt(?CarbonInterface $requested = null): CarbonInterface;

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    public function assertReady(Cart $cart, Collection $items, Collection $products): void;

    public function postCreationBehavior(): CheckoutPostCreationBehavior;
}
