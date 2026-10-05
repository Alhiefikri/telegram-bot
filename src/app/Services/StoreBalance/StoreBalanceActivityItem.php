<?php

namespace App\Services\StoreBalance;

use Carbon\CarbonImmutable;

final class StoreBalanceActivityItem
{
    public const TYPE_TOP_UP = StoreBalanceActivityCursor::TYPE_TOP_UP;

    public const TYPE_ENTRY = StoreBalanceActivityCursor::TYPE_ENTRY;

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KIND_TOP_UP = 'top_up';

    public const KIND_COMPENSATION = 'compensation';

    public const KIND_PURCHASE = 'purchase';

    public const KIND_REFUND = 'refund';

    public const STATE_CREDITED = 'credited';

    public const STATE_RECOVERED = 'recovered';

    public const STATE_COMPENSATED = 'compensated';

    public const STATE_PENDING = 'pending';

    public const STATE_UNCERTAIN = 'uncertain';

    public const STATE_EXPIRED = 'expired';

    public const STATE_REVIEW = 'review';

    public const STATE_FAILED = 'failed';

    public const STATE_DEBITED = 'debited';

    public const STATE_REFUNDED = 'refunded';

    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly CarbonImmutable $occurredAt,
        public readonly string $kind,
        public readonly string $state,
        public readonly int $amount,
        public readonly ?int $signedAmount,
        public readonly string $reference,
        public readonly ?int $requestedAmount = null,
        public readonly ?int $creditedAmount = null,
        public readonly ?int $relatedEntryId = null,
        public readonly bool $topUpCanCheck = false,
        public readonly bool $topUpCanRetry = false,
    ) {}

    public function isTopUp(): bool
    {
        return $this->type === self::TYPE_TOP_UP;
    }

    public function cursor(): StoreBalanceActivityCursor
    {
        return $this->isTopUp()
            ? StoreBalanceActivityCursor::topUp($this->occurredAt, $this->id)
            : StoreBalanceActivityCursor::entry($this->occurredAt, $this->id);
    }
}
