<?php

namespace App\Services\CustomerApi;

use App\Models\CustomerApiOrderClaim;

/**
 * Outcome of one durable claim acquisition: either this request owns the fenced
 * lease and must run the canonical checkout, or an earlier identical request
 * already produced an accepted Order that must be replayed unchanged.
 */
final readonly class CustomerApiOrderAcquisition
{
    private function __construct(public ?CustomerApiOrderClaim $claim, public ?string $acceptedOrderId) {}

    public static function leased(CustomerApiOrderClaim $claim): self
    {
        return new self($claim, null);
    }

    public static function accepted(string $orderId): self
    {
        return new self(null, $orderId);
    }
}
