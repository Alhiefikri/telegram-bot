<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Services\Binance\BinancePayClient;
use App\Services\Binance\BinanceUniquePaymentAmount;
use App\Services\Checkout\PaymentPlan\BinancePayCheckoutPaymentSnapshot;
use App\Support\PaymentProvider;
use Illuminate\Support\Collection;

final readonly class BinanceCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = 'BINANCE_PAY_ID';

    public const PAYMENT_CURRENCY = 'USDT';

    public const IDENTITY = PaymentProvider::MANUAL.'|'.self::METHOD;

    public function __construct(
        private BinancePayClient $binance,
        private BinanceUniquePaymentAmount $uniqueAmount,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::MANUAL,
            requestedMethod: self::METHOD,
        ));

        $setting = $context->lockedSetting;
        if (! $setting?->binance_pay_enabled) {
            throw new InvalidCheckout('Binance Pay is not ready.');
        }

        [$receiverType, $receiverValue] = $this->receiverSnapshot();
        $mode = $this->verificationMode($setting);
        $baseAmount = $this->baseAmount($context->items, $context->products);
        $expiresAt = now()->addMinutes($setting->binancePaymentTtlMinutes());

        $amount = $mode === 'amount_unique'
            ? $this->uniqueAmount->allocate($baseAmount)
            : $baseAmount;

        $snapshot = new BinancePayCheckoutPaymentSnapshot(
            provider: PaymentProvider::MANUAL,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
            verificationMode: $mode,
            autoVerificationEnabled: $this->binance->configured(),
            receiverType: $receiverType,
            receiverValue: $receiverValue,
            verificationStatus: $mode === 'order_id' ? 'awaiting_submission' : 'checking',
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::MANUAL,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::BinancePayInstructions,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function receiverSnapshot(): array
    {
        if (filled(config('services.binance.pay_id'))) {
            return ['pay_id', (string) config('services.binance.pay_id')];
        }

        if (filled(config('services.binance.pay_uid'))) {
            return ['uid', (string) config('services.binance.pay_uid')];
        }

        throw new InvalidCheckout('Binance Pay is not ready.');
    }

    private function verificationMode(ManualPaymentSetting $setting): string
    {
        return in_array($setting->binance_verification_mode, ['amount_unique', 'order_id'], true)
            ? $setting->binance_verification_mode
            : 'amount_unique';
    }

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    private function baseAmount(Collection $items, Collection $products): int
    {
        return $items->sum(function ($item) use ($products): int {
            $price = $products[$item->product_id]->usdt_price_amount;
            if ($price === null) {
                throw new InvalidCheckout('A cart item has no USDT price.');
            }

            return (int) $price * (int) $item->quantity;
        });
    }
}
