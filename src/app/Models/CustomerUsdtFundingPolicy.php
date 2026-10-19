<?php

namespace App\Models;

use App\Enums\CustomerUsdtFundingRail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned DB/Admin policy singleton for Customer USDT funding. H0-v1
 * disables business min/max/per-customer/aggregate caps, so this row carries
 * no cap column: only representational guards (strict positive micro-USDT
 * amounts, bounded confirmation TTL, nonnegative fee) plus the Owner
 * activation flag, terms version and optimistic version. The USD preset list
 * is presentation-only: it seeds the top-up amount buttons and never caps,
 * gates or rewrites a funding target, so changing the policy never touches
 * money.
 */
#[Fillable(['singleton_key', 'funding_enabled', 'confirmation_ttl_seconds', 'application_fee_micro', 'preset_amounts_micro', 'terms_version', 'version'])]
class CustomerUsdtFundingPolicy extends Model
{
    public const SINGLETON_KEY = 'customer_usdt_funding_policy';

    public const DEFAULT_CONFIRMATION_TTL_SECONDS = 600;

    public const MIN_CONFIRMATION_TTL_SECONDS = 60;

    public const MAX_CONFIRMATION_TTL_SECONDS = 3600;

    public const MAX_TERMS_BODY_LENGTH = 20_000;

    /** @var list<int> One, five, ten, twenty-five, fifty and one hundred USDT. */
    public const DEFAULT_PRESET_AMOUNTS_MICRO = [1_000_000, 5_000_000, 10_000_000, 25_000_000, 50_000_000, 100_000_000];

    public const MAX_PRESETS = 8;

    /** Representational ceiling: one million USDT in micro-USDT. */
    public const MAX_PRESET_AMOUNT_MICRO = 1_000_000_000_000;

    protected function casts(): array
    {
        return [
            'funding_enabled' => 'boolean',
            'confirmation_ttl_seconds' => 'integer',
            'application_fee_micro' => 'integer',
            'preset_amounts_micro' => 'array',
            'terms_version' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * The presentation-only USD presets, or the seeded defaults when the
     * stored value is not a usable non-empty list of positive micro-USDT
     * amounts.
     *
     * @return list<int>
     */
    public function presetAmountsMicro(): array
    {
        $stored = $this->preset_amounts_micro;

        if (! is_array($stored) || ! array_is_list($stored)
            || $stored === [] || count($stored) > self::MAX_PRESETS) {
            return self::DEFAULT_PRESET_AMOUNTS_MICRO;
        }

        foreach ($stored as $amount) {
            if (! is_int($amount) || $amount < 1 || $amount > self::MAX_PRESET_AMOUNT_MICRO) {
                return self::DEFAULT_PRESET_AMOUNTS_MICRO;
            }
        }

        return array_values($stored);
    }

    public static function current(): ?self
    {
        return self::query()->where('singleton_key', self::SINGLETON_KEY)->first();
    }

    public function fundingEnabled(): bool
    {
        return (bool) $this->funding_enabled;
    }

    public function confirmationTtlSeconds(): int
    {
        return (int) $this->confirmation_ttl_seconds;
    }

    public function applicationFeeMicro(): int
    {
        return (int) $this->application_fee_micro;
    }

    public function applicationFeeMicroForRail(CustomerUsdtFundingRail $rail): int
    {
        return $rail === CustomerUsdtFundingRail::BinancePay ? 0 : $this->applicationFeeMicro();
    }

    public function termsVersion(): int
    {
        return (int) $this->terms_version;
    }

    public function policyVersion(): int
    {
        return (int) $this->version;
    }
}
