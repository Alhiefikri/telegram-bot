<?php

namespace App\Services\Checkout;

use App\Enums\BalancePaymentPreference;
use App\Exceptions\CheckoutPriceChanged;
use App\Exceptions\InsufficientCheckoutBalance;
use App\Exceptions\InvalidCheckout;
use InvalidArgumentException;

/** Trusted checkout consent; amounts are whole IDR and micro-USDT, never floats. */
final readonly class BalanceCheckoutRequest
{
    public function __construct(
        public ?BalancePaymentPreference $preference = null,
        public ?int $expectedIdrTotal = null,
        public ?int $expectedUsdTotalMicro = null,
    ) {
        foreach ([$expectedIdrTotal, $expectedUsdTotalMicro] as $amount) {
            if ($amount !== null && $amount <= 0) {
                throw new InvalidArgumentException('Expected balance totals must be positive integers.');
            }
        }
    }

    public function select(
        BalancePaymentPreference $resolvedPreference,
        CheckoutPricingSnapshot $pricing,
        ?int $idrBalance,
        ?int $usdtBalance,
    ): CheckoutPaymentIntent {
        $totals = ['IDR' => $pricing->idrTotal(), 'USDT' => $pricing->usdtTotal()];
        $expected = ['IDR' => $this->expectedIdrTotal, 'USDT' => $this->expectedUsdTotalMicro];
        $currencies = $resolvedPreference->currencies();

        // Validate every authorized candidate before affordability, including a
        // stale secondary when the primary is affordable. Staleness is not fallback.
        foreach ($currencies as $index => $currency) {
            if ($expected[$currency] !== null && $expected[$currency] !== $totals[$currency]) {
                throw new CheckoutPriceChanged($pricing->idrTotal(), $pricing->usdtTotal());
            }
            if ($index === 0 && $totals[$currency] !== null && $expected[$currency] === null) {
                throw new InvalidCheckout('The primary currency requires an expected total.');
            }
        }

        foreach ($currencies as $currency) {
            $total = $totals[$currency];
            if ($total === null || $expected[$currency] === null) {
                continue;
            }
            if ($total <= 0) {
                throw new InvalidCheckout('Balance checkout requires a positive full total.');
            }
            $balance = $currency === 'IDR' ? $idrBalance : $usdtBalance;
            if ($balance === null) {
                throw new InvalidCheckout('The candidate wallet was not locked.');
            }
            if ($balance >= $total) {
                return $currency === 'IDR' ? CheckoutPaymentIntents::storeBalance() : CheckoutPaymentIntents::usdtBalance();
            }
        }

        throw new InsufficientCheckoutBalance('No authorized eligible wallet can cover the full purchase.');
    }
}
