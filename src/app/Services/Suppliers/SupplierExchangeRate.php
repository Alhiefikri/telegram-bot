<?php

namespace App\Services\Suppliers;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use JsonException;

/**
 * Plausible IDR value of one supplier currency unit.
 *
 * `supplier_currency_rates.exchange_rate_idr` is the authoritative global value;
 * `suppliers.exchange_rate_idr` is the synchronized connection cache read by
 * pricing and checkout snapshots. One VND is worth well under one rupiah, so a
 * rupiah-per-USDT figure (the 18.000 that broke a live Nastele connection) is not
 * a currency conversion at all and silently produced a hundredfold unit cost. The
 * band is deliberately wide: it rejects order-of-magnitude mistakes without
 * freezing a legitimate funding-cost move. Currencies without a band are only
 * checked for a positive, bounded decimal by `isStorable()`.
 */
final class SupplierExchangeRate
{
    /**
     * Wallet currencies reported by supplier contracts. USD and USDT share one
     * editable rate because they are funded from the same dollar budget; the
     * separate connection caches are still keyed by their exact currency so
     * checkout and purchase snapshots stay explicit.
     *
     * @var list<string>
     */
    public const CURRENCIES = ['USD', 'USDT', 'VND'];

    /** Editable global rate rows. USD covers both USD and USDT connections. */
    public const RATE_KEYS = ['USD', 'VND'];

    /** Resolve a supplier currency to the row that owns its editable rate. */
    public static function rateKey(?string $currency): ?string
    {
        return match ($currency) {
            'USD', 'USDT' => 'USD',
            'VND' => 'VND',
            default => $currency,
        };
    }

    /** @return list<string> supplier currencies served by one rate row */
    public static function currenciesForRateKey(string $key): array
    {
        return $key === 'USD' ? ['USD', 'USDT'] : [$key];
    }

    public static function rateLabel(string $key): string
    {
        return $key === 'USD' ? 'USD / USDT' : $key;
    }

    /** @var array<string, array{min: string, max: string}> */
    private const BANDS = [
        // 0.692308 = Rp18.000 per USDT ÷ 26.000 VND per USDT.
        'VND' => ['min' => '0.1', 'max' => '0.999999'],
    ];

    /** @return array{min: string, max: string}|null */
    public static function band(string $currency): ?array
    {
        return self::BANDS[$currency] ?? null;
    }

    public static function isPlausible(?string $currency, int|float|string|null $rate): bool
    {
        $band = $currency === null ? null : self::band($currency);
        if ($band === null) {
            return true;
        }
        $decimal = self::decimal($rate);

        return $decimal !== null
            && $decimal->compareTo(BigDecimal::of($band['min'])) >= 0
            && $decimal->compareTo(BigDecimal::of($band['max'])) <= 0;
    }

    /**
     * True when the value can be persisted as one currency's IDR rate: a
     * positive six-place decimal within the configured connection ceiling, and
     * inside the currency's plausible band when one exists.
     */
    public static function isStorable(string $currency, int|float|string|null $rate): bool
    {
        $decimal = self::decimal($rate);

        return $decimal !== null
            && $decimal->compareTo(BigDecimal::of('0')) > 0
            && $decimal->compareTo(BigDecimal::of('1000000')) <= 0
            && self::isPlausible($currency, $rate);
    }

    /** True when two stored decimal tokens describe the same rate value. */
    public static function equals(int|float|string|null $left, int|float|string|null $right): bool
    {
        $a = self::decimal($left);
        $b = self::decimal($right);

        return $a !== null && $b !== null && $a->compareTo($b) === 0;
    }

    /** Operator-facing Indonesian explanation. It never echoes a submitted value or credential. */
    public static function reason(string $currency): ?string
    {
        return match ($currency) {
            'VND' => 'Kurs supplier tidak wajar: nilai rupiah untuk 1 VND harus di antara 0.1 dan 0.999999 (contoh 0.692308 = Rp18.000 ÷ 26.000 VND), bukan rupiah per USDT.',
            default => null,
        };
    }

    /** The same rule stated as a requirement for one connection provider. */
    public static function requirement(string $label, string $currency): ?string
    {
        return match ($currency) {
            'VND' => 'Kurs '.$label.' harus dalam rupiah untuk 1 VND (contoh 0.692308 = Rp18.000 ÷ 26.000 VND), bukan rupiah per USDT.',
            default => null,
        };
    }

    /** Exact decimal tokens only; floats are accepted at the request boundary, never in arithmetic. */
    private static function decimal(int|float|string|null $rate): ?BigDecimal
    {
        if (is_float($rate)) {
            try {
                $rate = json_encode($rate, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }
        if (is_int($rate)) {
            $rate = (string) $rate;
        }
        if (! is_string($rate) || strlen($rate) > 32 || ! preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/D', $rate)) {
            return null;
        }
        try {
            return BigDecimal::of($rate);
        } catch (MathException) {
            return null;
        }
    }
}
