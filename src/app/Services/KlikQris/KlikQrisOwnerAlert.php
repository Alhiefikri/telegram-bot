<?php

namespace App\Services\KlikQris;

/**
 * One actionable KlikQRIS outcome that needs owner intervention.
 *
 * Carries only the module's safe outcome classification, diagnostic reason,
 * and observation count. No provider payload, QR media, secret, or customer
 * data ever reaches an owner-facing adapter through this object.
 */
final readonly class KlikQrisOwnerAlert
{
    public function __construct(
        private KlikQrisNativeQrisOutcome $outcome,
        private int $observations,
        private int $retryBudget,
        private ?string $reason,
        private ?string $createReason,
    ) {}

    public function outcome(): KlikQrisNativeQrisOutcome
    {
        return $this->outcome;
    }

    public function observations(): int
    {
        return $this->observations;
    }

    public function retryBudget(): int
    {
        return $this->retryBudget;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function createReason(): ?string
    {
        return $this->createReason;
    }
}
