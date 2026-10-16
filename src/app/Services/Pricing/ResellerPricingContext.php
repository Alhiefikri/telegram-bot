<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Trusted proof that a quote request belongs to the Reseller channel.
 *
 * Only the authenticated API boundary may construct this value; client input is
 * never allowed to establish reseller authority or change retail pricing. The
 * context carries the authenticated actor and origin for snapshots and audit,
 * never price terms: every amount still comes from the Product-owned schedule.
 */
final readonly class ResellerPricingContext
{
    public const ORIGIN_API = 'api';

    private function __construct(
        public string $origin,
        public int $customerId,
    ) {
        if ($origin !== self::ORIGIN_API) {
            throw new InvalidArgumentException('A reseller pricing context requires a supported origin.');
        }

        if ($customerId < 1) {
            throw new InvalidArgumentException('A reseller pricing context requires an authenticated Customer.');
        }
    }

    public static function forAuthenticatedApiRequest(int $customerId): self
    {
        return new self(self::ORIGIN_API, $customerId);
    }
}
