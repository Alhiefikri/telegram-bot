<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingReleaseKind;
use InvalidArgumentException;

/**
 * Typed release authority for one Customer USDT funding reservation. A
 * reservation is never released by expiry or by a generic "cancel": only a
 * downstream process that can name one of these verified resolutions may
 * release the slot, and the resolution identity is stored on the row. The
 * economic proof behind each named constructor stays with the minting service
 * (rail recovery, settlement or exception review).
 */
final readonly class CustomerUsdtFundingRelease
{
    private const TOKEN_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_.:\-]{0,190}\z/D';

    private function __construct(
        public CustomerUsdtFundingReleaseKind $kind,
        public string $reference,
        public string $actor,
    ) {}

    /** The target was never exposed and no provider order was ever created. */
    public static function neverExposed(string $reference, string $actor): self
    {
        return self::of(CustomerUsdtFundingReleaseKind::NeverExposed, $reference, $actor);
    }

    /** The provider authoritatively declined/cancelled before any payment. */
    public static function providerDeclined(string $reference, string $actor): self
    {
        return self::of(CustomerUsdtFundingReleaseKind::ProviderDeclined, $reference, $actor);
    }

    /** Observed funds were returned externally to the sender. */
    public static function externallyReturned(string $reference, string $actor): self
    {
        return self::of(CustomerUsdtFundingReleaseKind::ExternallyReturned, $reference, $actor);
    }

    /** An owner-approved compensation resolved the ambiguous money. */
    public static function compensated(string $reference, string $actor): self
    {
        return self::of(CustomerUsdtFundingReleaseKind::Compensated, $reference, $actor);
    }

    public static function of(CustomerUsdtFundingReleaseKind $kind, string $reference, string $actor): self
    {
        if (preg_match(self::TOKEN_PATTERN, $reference) !== 1) {
            throw new InvalidArgumentException('USDT funding release reference must be a bounded sanitized token.');
        }
        if (preg_match(self::TOKEN_PATTERN, $actor) !== 1) {
            throw new InvalidArgumentException('USDT funding release actor must be a bounded sanitized token.');
        }

        return new self($kind, $reference, $actor);
    }
}
