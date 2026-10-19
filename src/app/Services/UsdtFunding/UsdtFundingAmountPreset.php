<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use App\Models\CustomerUsdtFundingPolicy;
use InvalidArgumentException;
use LogicException;

/**
 * Signed, versioned identity for one USD top-up preset button. The callback
 * carries no amount: the current policy resolves the exact micro-USDT value at
 * tap time, so a button from an older list can never fund an amount the Owner
 * already changed or removed.
 */
final class UsdtFundingAmountPreset
{
    private const TOKEN_BYTES = 8;

    private const TOKEN_LENGTH = 11;

    private const RAIL_PATTERN = 'binance_pay|bsc';

    private const VERSION_PATTERN = '(?:0|[1-9][0-9]{0,9})';

    private function __construct(
        public readonly CustomerUsdtFundingRail $rail,
        public readonly int $version,
        public readonly int $index,
        public readonly int $amountMicro,
    ) {}

    /** Short stable token the rail uses inside callback data. */
    public static function railToken(CustomerUsdtFundingRail $rail): string
    {
        return $rail === CustomerUsdtFundingRail::BinancePay ? 'binance_pay' : 'bsc';
    }

    public static function railFromToken(string $token): CustomerUsdtFundingRail
    {
        return $token === 'binance_pay'
            ? CustomerUsdtFundingRail::BinancePay
            : CustomerUsdtFundingRail::BscShared;
    }

    /** The amount-list callback of one rail: `balance:usd:<rail>`. */
    public static function listCallback(CustomerUsdtFundingRail $rail): string
    {
        return 'balance:usd:'.self::railToken($rail);
    }

    /** The custom-amount callback of one rail: `balance:usd:<rail>:x`. */
    public static function customCallback(CustomerUsdtFundingRail $rail): string
    {
        return self::listCallback($rail).':x';
    }

    public static function isCustomCallbackFormat(string $data): bool
    {
        return strlen($data) <= 64
            && preg_match('/\Abalance:usd:('.self::RAIL_PATTERN.'):x\z/D', $data) === 1;
    }

    public static function isPresetCallbackFormat(string $data): bool
    {
        return strlen($data) <= 64
            && preg_match('/\Abalance:usd:('.self::RAIL_PATTERN.'):p:'.self::VERSION_PATTERN.':[A-Za-z0-9_-]{'.self::TOKEN_LENGTH.'}\z/D', $data) === 1;
    }

    /** The plain rail callback of one rail: `balance:usd:<rail>`. */
    public static function railFromCallback(string $data): ?CustomerUsdtFundingRail
    {
        if (preg_match('/\Abalance:usd:(?<rail>'.self::RAIL_PATTERN.')\z/D', $data, $matches) !== 1) {
            return null;
        }

        return self::railFromToken((string) $matches['rail']);
    }

    /** The rail named by a custom-amount callback, or null when it is not one. */
    public static function customRail(string $data): ?CustomerUsdtFundingRail
    {
        if (preg_match('/\Abalance:usd:(?<rail>'.self::RAIL_PATTERN.'):x\z/D', $data, $matches) !== 1) {
            return null;
        }

        return self::railFromToken((string) $matches['rail']);
    }

    /** @param list<int> $presetAmountsMicro */
    public static function presetCallback(
        int $version,
        CustomerUsdtFundingRail $rail,
        int $index,
        array $presetAmountsMicro,
    ): string {
        if (! array_key_exists($index, $presetAmountsMicro)) {
            throw new InvalidArgumentException('USD top-up preset index is invalid.');
        }

        return self::listCallback($rail).':p:'.$version.':'.self::identity($version, $rail, $index, $presetAmountsMicro[$index]);
    }

    public static function resolve(string $data, ?CustomerUsdtFundingPolicy $policy): ?self
    {
        if (! self::isPresetCallbackFormat($data)) {
            return null;
        }

        preg_match(
            '/\Abalance:usd:(?<rail>'.self::RAIL_PATTERN.'):p:(?<version>'.self::VERSION_PATTERN.'):(?<identity>[A-Za-z0-9_-]{'.self::TOKEN_LENGTH.'})\z/D',
            $data,
            $matches,
        );
        $version = self::parseVersion((string) ($matches['version'] ?? ''));
        if ($policy === null || $version === null || $policy->policyVersion() !== $version) {
            return null;
        }

        $rail = self::railFromToken((string) ($matches['rail'] ?? ''));
        $identity = (string) ($matches['identity'] ?? '');
        foreach ($policy->presetAmountsMicro() as $index => $amountMicro) {
            if (hash_equals(self::identity($version, $rail, $index, $amountMicro), $identity)) {
                return new self($rail, $version, $index, $amountMicro);
            }
        }

        return null;
    }

    private static function identity(
        int $version,
        CustomerUsdtFundingRail $rail,
        int $index,
        int $amountMicro,
    ): string {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('Application key is required for USD top-up callbacks.');
        }

        $digest = hash_hmac(
            'sha256',
            $version.'|'.$rail->value.'|'.$index.'|'.$amountMicro,
            $key,
            true,
        );

        return rtrim(strtr(base64_encode(substr($digest, 0, self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private static function parseVersion(string $value): ?int
    {
        if ($value === ''
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
