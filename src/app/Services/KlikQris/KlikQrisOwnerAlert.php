<?php

namespace App\Services\KlikQris;

/**
 * One actionable KlikQRIS outcome that needs owner intervention.
 *
 * Carries only the module's safe outcome classification and the number of times
 * it has been observed. No provider payload, QR media, secret, or customer data
 * ever reaches an owner-facing adapter through this object.
 */
final readonly class KlikQrisOwnerAlert
{
    public function __construct(
        private KlikQrisNativeQrisOutcome $outcome,
        private int $observations,
        private int $retryBudget,
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
}
