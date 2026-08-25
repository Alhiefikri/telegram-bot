<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class UsdtBep20CheckoutIntent implements CheckoutPaymentIntent
{
    use CheckoutPaymentPreparationCompatibility;

    public const METHOD = 'USDT_BEP20';

    public function __construct(private ?CarbonInterface $deadline = null) {}

    public function provider(): string
    {
        return PaymentProvider::MANUAL;
    }

    public function method(): string
    {
        return self::METHOD;
    }

    public function orderCurrency(): string
    {
        return 'IDR';
    }

    public function paymentAmount(Collection $items, Collection $products): int
    {
        return $items->sum(function ($item) use ($products): int {
            $price = $products[$item->product_id]->usdt_price_amount;
            if ($price === null) {
                throw new InvalidCheckout('A cart item has no USDT price.');
            }

            return (int) $price * (int) $item->quantity;
        });
    }

    public function paymentCurrency(): string
    {
        return 'USDT';
    }

    public function expiresAt(
        ?CarbonInterface $requested = null,
        ?ManualPaymentSetting $setting = null,
    ): CarbonInterface {
        return $requested
            ?? $this->deadline
            ?? now()->addMinutes(($setting ?? ManualPaymentSetting::current())?->bscPaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_BSC_PAYMENT_TTL_MINUTES);
    }

    public function assertReady(
        Cart $cart,
        Collection $items,
        Collection $products,
        ?ManualPaymentSetting $setting = null,
    ): void {
        if (! ($setting ?? ManualPaymentSetting::current())?->bsc_payment_enabled) {
            throw new InvalidCheckout('BSC payment rail is no longer enabled. Try again.');
        }

        foreach ($items as $item) {
            if ($products[$item->product_id]->usdt_price_amount === null) {
                throw new InvalidCheckout('A cart item has no USDT price.');
            }
        }
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::BscInstructions;
    }
}
