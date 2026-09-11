<?php

namespace App\Services\StoreBalance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class StoreBalanceActivityCursor
{
    public const TYPE_TOP_UP = 'top_up';

    public const TYPE_ENTRY = 'entry';

    public const TOP_UP_RANK = 1;

    public const ENTRY_RANK = 2;

    private function __construct(
        public readonly string $type,
        public readonly int $occurredAtMicros,
        public readonly int $id,
    ) {
        if (! in_array($type, [self::TYPE_TOP_UP, self::TYPE_ENTRY], true)) {
            throw new InvalidArgumentException('Unsupported Store Balance activity cursor type.');
        }

        if ($occurredAtMicros < 1 || $id < 1) {
            throw new InvalidArgumentException('Store Balance activity cursor values must be positive.');
        }
    }

    public static function topUp(CarbonInterface $occurredAt, int $id): self
    {
        return new self(self::TYPE_TOP_UP, self::timestampMicros($occurredAt), $id);
    }

    public static function entry(CarbonInterface $occurredAt, int $id): self
    {
        return new self(self::TYPE_ENTRY, self::timestampMicros($occurredAt), $id);
    }

    public static function fromRank(int $rank, int $occurredAtMicros, int $id): ?self
    {
        return match ($rank) {
            self::TOP_UP_RANK => new self(self::TYPE_TOP_UP, $occurredAtMicros, $id),
            self::ENTRY_RANK => new self(self::TYPE_ENTRY, $occurredAtMicros, $id),
            default => null,
        };
    }

    public function rank(): int
    {
        return $this->type === self::TYPE_TOP_UP
            ? self::TOP_UP_RANK
            : self::ENTRY_RANK;
    }

    public function occurredAt(): CarbonImmutable
    {
        $seconds = intdiv($this->occurredAtMicros, 1_000_000);
        $microseconds = $this->occurredAtMicros % 1_000_000;

        return CarbonImmutable::createFromTimestampUTC($seconds)
            ->setMicrosecond($microseconds)
            ->setTimezone((string) config('app.timezone'));
    }

    private static function timestampMicros(CarbonInterface $occurredAt): int
    {
        return ((int) $occurredAt->format('U')) * 1_000_000 + (int) $occurredAt->format('u');
    }
}
