<?php

namespace App\Services\UsdtBalance;

use Carbon\CarbonImmutable;

/**
 * One Customer-facing USDT history row. Target rows describe a confirmed
 * funding request that has not reached a terminal resolution; entry rows
 * describe one immutable ledger posting with its exact signed micro amount.
 */
final class CustomerUsdtActivityItem
{
    public const TYPE_TARGET = CustomerUsdtActivityCursor::TYPE_TARGET;

    public const TYPE_ENTRY = CustomerUsdtActivityCursor::TYPE_ENTRY;

    public const KIND_TOP_UP = 'top_up';

    public const STATE_CREDITED = 'credited';

    public const STATE_DEBITED = 'debited';

    public const STATE_REFUNDED = 'refunded';

    public const STATE_REVERSED = 'reversed';

    public function __construct(
        public readonly string $type,
        public readonly int $cursorId,
        public readonly ?string $targetId,
        public readonly CarbonImmutable $occurredAt,
        public readonly string $kind,
        public readonly string $state,
        public readonly int $amountMicro,
        public readonly ?int $signedMicro,
        public readonly string $reference,
    ) {}

    public function isTarget(): bool
    {
        return $this->type === self::TYPE_TARGET;
    }

    public function cursor(): CustomerUsdtActivityCursor
    {
        return $this->isTarget()
            ? CustomerUsdtActivityCursor::target($this->cursorId, $this->occurredAt)
            : CustomerUsdtActivityCursor::entry($this->cursorId, $this->occurredAt);
    }
}
