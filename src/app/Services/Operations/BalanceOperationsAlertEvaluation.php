<?php

namespace App\Services\Operations;

/**
 * Bounded result of one balance operations alert evaluation pass (#558).
 * Every field is a plain count of episode transitions; nothing here carries
 * customer, chain or provider identity.
 */
final readonly class BalanceOperationsAlertEvaluation
{
    public function __construct(
        public int $opened = 0,
        public int $updated = 0,
        public int $resolved = 0,
        public int $notified = 0,
        public int $deliveryPending = 0,
    ) {}

    /** @return array{opened: int, updated: int, resolved: int, notified: int, delivery_pending: int} */
    public function toArray(): array
    {
        return [
            'opened' => $this->opened,
            'updated' => $this->updated,
            'resolved' => $this->resolved,
            'notified' => $this->notified,
            'delivery_pending' => $this->deliveryPending,
        ];
    }
}
