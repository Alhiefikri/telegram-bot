<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class CheckoutPaymentPreparationContext
{
    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    public function __construct(
        public Cart $cart,
        public Collection $items,
        public Collection $products,
        public ?ManualPaymentSetting $lockedSetting,
        public CheckoutPaymentPreflight $preflight,
        public ?CarbonInterface $requestedExpiresAt = null,
    ) {}
}
