<?php

namespace App\Services\Operations;

/** Sanitized result of one post-restore external evidence replay (#558). */
final readonly class BalanceRestoreReplayResult
{
    public function __construct(
        public int $settlementChecked,
        public int $settlementSettled,
        public int $settlementReview,
        public int $settlementUnchanged,
        public int $settlementFailed,
        public int $outboundQuarantined,
        public bool $dryRun,
        public string $scanStatus,
        public int $scanRequests,
        public ?string $replayCompletedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'settlement' => [
                'checked' => $this->settlementChecked,
                'settled' => $this->settlementSettled,
                'review' => $this->settlementReview,
                'unchanged' => $this->settlementUnchanged,
                'failed' => $this->settlementFailed,
            ],
            'outbound' => [
                'quarantined' => $this->outboundQuarantined,
            ],
            'scan' => [
                'status' => $this->scanStatus,
                'requests' => $this->scanRequests,
            ],
            'replay_completed_at' => $this->replayCompletedAt,
        ];
    }
}
