<?php

namespace App\Services\Checkout;

final class CheckoutPaymentIntents
{
    public const RAIL_QRIS = 'qris';

    public const RAIL_STORE_BALANCE = 'store_balance';

    public const RAIL_BINANCE_PAY = 'binance_pay';

    public const RAIL_BINANCE_PAY_ID = 'binance_pay_id';

    public const RAIL_USDT_BEP20 = 'usdt_bep20';

    /** @return list<string> */
    public static function supportedPaymentRails(): array
    {
        return [self::RAIL_QRIS, self::RAIL_STORE_BALANCE, self::RAIL_BINANCE_PAY_ID, self::RAIL_USDT_BEP20];
    }

    /** @return list<string> */
    public static function acceptedPaymentRails(): array
    {
        return [self::RAIL_QRIS, self::RAIL_BINANCE_PAY_ID, self::RAIL_USDT_BEP20, self::RAIL_BINANCE_PAY];
    }

    public static function normalizePaymentRail(?string $paymentRail): string
    {
        $paymentRail = strtolower(trim((string) $paymentRail));

        return $paymentRail === self::RAIL_BINANCE_PAY
            ? self::RAIL_BINANCE_PAY_ID
            : ($paymentRail !== '' ? $paymentRail : self::RAIL_QRIS);
    }

    public static function isSupportedPaymentRail(?string $paymentRail): bool
    {
        return in_array(self::normalizePaymentRail($paymentRail), self::supportedPaymentRails(), true);
    }

    public static function isBinancePaymentRail(?string $paymentRail): bool
    {
        return in_array(self::normalizePaymentRail($paymentRail), [self::RAIL_BINANCE_PAY_ID, self::RAIL_USDT_BEP20], true);
    }

    public static function isBinancePayIdRail(?string $paymentRail): bool
    {
        return self::normalizePaymentRail($paymentRail) === self::RAIL_BINANCE_PAY_ID;
    }

    public static function isUsdtBep20Rail(?string $paymentRail): bool
    {
        return self::normalizePaymentRail($paymentRail) === self::RAIL_USDT_BEP20;
    }

    public static function isBinancePaymentMethod(?string $paymentMethod): bool
    {
        return in_array(strtoupper(trim((string) $paymentMethod)), [
            BinancePayCheckoutIntent::METHOD,
            UsdtBep20CheckoutIntent::METHOD,
        ], true);
    }

    public static function manualQris(): ManualQrisCheckoutIntent
    {
        return new ManualQrisCheckoutIntent;
    }

    public static function storeBalance(): StoreBalanceCheckoutIntent
    {
        return new StoreBalanceCheckoutIntent;
    }

    public static function binancePayId(): BinancePayCheckoutIntent
    {
        return new BinancePayCheckoutIntent;
    }

    public static function binancePay(): BinancePayCheckoutIntent
    {
        return self::binancePayId();
    }

    public static function usdtBep20(): UsdtBep20CheckoutIntent
    {
        return new UsdtBep20CheckoutIntent;
    }

    public static function klikQris(): KlikQrisCheckoutIntent
    {
        return new KlikQrisCheckoutIntent;
    }

    public static function sakurupiah(): SakurupiahCheckoutIntent
    {
        return new SakurupiahCheckoutIntent;
    }
}
