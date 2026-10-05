<?php

namespace App\Services\UsdtTreasury;

use App\Enums\UsdtTreasuryMovementDirection;
use App\Services\UsdtBalance\UsdtMicroAmount;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One operator-recorded custody movement input. The reference must uniquely
 * identify the external movement (for example a signed sweep transaction
 * hash); the reason and actor are audit metadata. Recording is bookkeeping:
 * the application never signs, sends or credits funds here.
 */
final readonly class UsdtTreasuryMovementInput
{
    public function __construct(
        public UsdtTreasuryMovementDirection $direction,
        public UsdtMicroAmount $amount,
        public string $reference,
        public string $reason,
        public string $actor,
        public DateTimeInterface $occurredAt,
    ) {
        foreach (['reference' => $reference, 'reason' => $reason, 'actor' => $actor] as $label => $value) {
            if (trim($value) === '' || mb_strlen($value) > 191 || preg_match('/[^\x20-\x7E]/', $value) === 1) {
                throw new InvalidArgumentException("USDT treasury movement {$label} must be non-empty printable ASCII of at most 191 characters.");
            }
        }
    }
}
