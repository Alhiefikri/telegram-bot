<?php

namespace App\Services\Checkout;

use App\Services\TelegramStars\StarsPricingPolicy;
use Carbon\CarbonImmutable;

/** Advisory quote retained by the server, not serialized into callback data. */
final readonly class TelegramStarsCheckoutQuote
{
    public function __construct(
        public TelegramStarsConfirmation $confirmation,
        public ?int $cartId,
        public string $selectionFingerprint,
        public StarsPricingPolicy $policy,
        public int $subtotalIdr,
        public int $totalXtr,
        public CarbonImmutable $expiresAt,
    ) {}
}
