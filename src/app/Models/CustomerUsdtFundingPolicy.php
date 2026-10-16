<?php

namespace App\Models;

use App\Enums\CustomerUsdtFundingRail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned DB/Admin policy singleton for Customer USDT funding. H0-v1
 * disables business min/max/per-customer/aggregate caps and presets, so this
 * row carries no cap column: only representational guards (strict positive
 * micro-USDT amounts, bounded confirmation TTL, nonnegative fee) plus the
 * Owner activation flag, terms version and optimistic version. Changing the
 * policy never touches money.
 */
#[Fillable(['singleton_key', 'funding_enabled', 'confirmation_ttl_seconds', 'application_fee_micro', 'terms_version', 'version'])]
class CustomerUsdtFundingPolicy extends Model
{
    public const SINGLETON_KEY = 'customer_usdt_funding_policy';

    public const DEFAULT_CONFIRMATION_TTL_SECONDS = 600;

    public const MIN_CONFIRMATION_TTL_SECONDS = 60;

    public const MAX_CONFIRMATION_TTL_SECONDS = 3600;

    public const MAX_TERMS_BODY_LENGTH = 20_000;

    protected function casts(): array
    {
        return [
            'funding_enabled' => 'boolean',
            'confirmation_ttl_seconds' => 'integer',
            'application_fee_micro' => 'integer',
            'terms_version' => 'integer',
            'version' => 'integer',
        ];
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
