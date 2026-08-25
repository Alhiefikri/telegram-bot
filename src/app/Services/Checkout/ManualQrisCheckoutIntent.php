<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class ManualQrisCheckoutIntent implements CheckoutPaymentIntent
{
    use CheckoutPaymentPreparationCompatibility;

    public function __construct(private ?CarbonInterface $deadline = null) {}

    public function provider(): string
    {
        return PaymentProvider::MANUAL;
    }

    public function method(): string
    {
        return 'QR_MANUAL';
    }

    public function orderCurrency(): string
    {
        return 'IDR';
    }

    public function paymentAmount(Collection $items, Collection $products): int
    {
        return $items->sum(fn ($item): int => $products[$item->product_id]->price_amount * $item->quantity);
    }

    public function paymentCurrency(): string
    {
        return 'IDR';
    }

    public function expiresAt(
        ?CarbonInterface $requested = null,
        ?ManualPaymentSetting $setting = null,
    ): CarbonInterface {
        return $requested
            ?? $this->deadline
            ?? now()->addMinutes(($setting ?? ManualPaymentSetting::current())?->qrisPaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES);
    }

    public function assertReady(
        Cart $cart,
        Collection $items,
        Collection $products,
        ?ManualPaymentSetting $setting = null,
    ): void {
        //
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::ManualQrisInstructions;
    }
}
