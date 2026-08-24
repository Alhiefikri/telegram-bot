<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class BinancePayCheckoutIntent implements CheckoutPaymentIntent
{
    public const METHOD = 'BINANCE_PAY_ID';

    public function __construct(private ?CarbonInterface $deadline = null) {}

    public function provider(): string
    {
        return 'manual';
    }

    public function method(): string
    {
        return self::METHOD;
    }

    public function verificationMode(?Cart $cart = null, ?ManualPaymentSetting $setting = null): string
    {
        $mode = ($setting ?? ManualPaymentSetting::current())?->binance_verification_mode;

        return in_array($mode, ['amount_unique', 'order_id'], true) ? $mode : 'amount_unique';
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

            return $price * $item->quantity;
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
            ?? now()->addMinutes(($setting ?? ManualPaymentSetting::current())?->binancePaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES);
    }

    public function assertReady(
        Cart $cart,
        Collection $items,
        Collection $products,
        ?ManualPaymentSetting $setting = null,
    ): void {
        $receiverConfigured = filled(config('services.binance.pay_id')) || filled(config('services.binance.pay_uid'));
        if (! ($setting ?? ManualPaymentSetting::current())?->binance_pay_enabled || ! $receiverConfigured) {
            throw new InvalidCheckout('Binance Pay is not ready.');
        }

        foreach ($items as $item) {
            if ($products[$item->product_id]->usdt_price_amount === null) {
                throw new InvalidCheckout('A cart item has no USDT price.');
            }
        }
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::BinancePayInstructions;
    }
}
