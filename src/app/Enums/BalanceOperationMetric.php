<?php

namespace App\Enums;

/**
 * Bounded-cardinality operational metric families for the USDT balance
 * subsystem. Every family owns a closed outcome allowlist, so a metric row can
 * never carry customer identity, chain hashes, provider payloads or any other
 * unbounded label.
 */
enum BalanceOperationMetric: string
{
    /** One Customer USDT Balance mutation attempt. */
    case LedgerOutcome = 'ledger_outcome';

    /** One retried database transaction in a balance money seam. */
    case LockRetry = 'lock_retry';

    /** One external money receipt claim outcome. */
    case ReceiptClaimOutcome = 'receipt_claim_outcome';

    /** One funding reservation attempt outcome. */
    case FundingReservationOutcome = 'funding_reservation_outcome';

    /** One balance outbound delivery claim outcome. */
    case OutboundDeliveryOutcome = 'outbound_delivery_outcome';

    /** @return list<string> */
    public function outcomes(): array
    {
        return match ($this) {
            self::LedgerOutcome => [
                'posted',
                'replayed',
                'insufficient_funds',
                'frozen',
                'projection_drift',
                'identity_conflict',
            ],
            self::LockRetry => [
                'deadlock',
                'concurrency',
            ],
            self::ReceiptClaimOutcome => [
                'claimed',
                'replayed',
                'conflict',
                'review',
            ],
            self::FundingReservationOutcome => [
                'reserved',
                'existing_active',
                'replayed',
                'conflict',
            ],
            self::OutboundDeliveryOutcome => [
                'claimed',
                'sent',
                'failed',
                'unknown',
                'skipped_paused',
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LedgerOutcome => 'Ledger mutation outcome',
            self::LockRetry => 'Database lock retry',
            self::ReceiptClaimOutcome => 'Receipt claim outcome',
            self::FundingReservationOutcome => 'Funding reservation outcome',
            self::OutboundDeliveryOutcome => 'Balance delivery outcome',
        };
    }
}
