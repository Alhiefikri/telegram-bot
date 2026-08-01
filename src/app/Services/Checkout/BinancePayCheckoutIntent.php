<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class BinancePayCheckoutIntent implements CheckoutPaymentIntent
{
    public function __construct(private ?CarbonInterface $deadline = null) {}

    public function provider(): string
    {
        return 'manual';
    }

    public function method(): string
    {
        return 'BINANCE_PAY_ID';
    }

    public function verificationMode(?Cart $cart = null): string
    {
        $settingRecord = ManualPaymentSetting::current();
        $setting = $settingRecord?->binance_verification_mode;
        $ready = $settingRecord?->binance_order_id_compatibility_ready_at !== null;

        if (! $ready && $this->isAdminCanary($cart)) {
            return 'order_id';
        }

        return in_array($setting, ['amount_unique', 'order_id'], true) && $ready
            ? (string) $setting
            : 'amount_unique';
    }

    private function isAdminCanary(?Cart $cart): bool
    {
        $adminId = trim((string) config('services.telegram.admin_user_id'));
        $customerId = trim((string) $cart?->customer?->telegram_id);

        return $adminId !== ''
            && hash_equals($adminId, $customerId)
            && filled(config('services.binance.api_key'))
            && filled(config('services.binance.api_secret'))
            && (filled(config('services.binance.pay_id')) || filled(config('services.binance.pay_uid')));
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

    public function expiresAt(?CarbonInterface $requested = null): CarbonInterface
    {
        return $requested
            ?? $this->deadline
            ?? now()->addMinutes(ManualPaymentSetting::current()?->binancePaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES);
    }

    public function assertReady(Cart $cart, Collection $items, Collection $products): void
    {
        $receiverConfigured = filled(config('services.binance.pay_id')) || filled(config('services.binance.pay_uid'));
        if (! ManualPaymentSetting::current()?->binance_pay_enabled || ! $receiverConfigured) {
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
