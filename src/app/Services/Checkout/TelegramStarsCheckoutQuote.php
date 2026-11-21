<?php

namespace App\Services\Checkout;

use App\Services\TelegramStars\StarsPricingPolicy;
use Carbon\CarbonImmutable;

/** Advisory quote retained by the server, not serialized into callback data. */
final readonly class TelegramStarsCheckoutQuote
{
    /** @param list<array{product_id: int, quantity: int, base_unit_idr: int, unit_idr: int, tier_minimum: ?int, subtotal_idr: int}> $retailLines */
    public function __construct(
        public TelegramStarsConfirmation $confirmation,
        public ?int $cartId,
        public string $selectionFingerprint,
        public StarsPricingPolicy $policy,
        public int $subtotalIdr,
        public int $totalXtr,
        public CarbonImmutable $expiresAt,
        public array $retailLines = [],
    ) {}

    public function expiringAt(CarbonImmutable $expiresAt): self
    {
        return new self(
            $this->confirmation, $this->cartId, $this->selectionFingerprint,
            $this->policy, $this->subtotalIdr, $this->totalXtr, $expiresAt, $this->retailLines,
        );
    }
}
