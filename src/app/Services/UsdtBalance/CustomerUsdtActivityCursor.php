<?php

namespace App\Services\UsdtBalance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Keyset position inside one Customer's USDT balance history. Targets and
 * ledger entries share one ordered stream: rank orders the two sources inside
 * the same timestamp, and the id is the target intent identity or entry id.
 */
final class CustomerUsdtActivityCursor
{
    public const TYPE_TARGET = 'target';

    public const TYPE_ENTRY = 'entry';

    public const TARGET_RANK = 1;

    public const ENTRY_RANK = 2;

    private function __construct(
        public readonly string $type,
        public readonly int $occurredAtMicros,
        public readonly int $id,
    ) {
        if (! in_array($type, [self::TYPE_TARGET, self::TYPE_ENTRY], true)) {
            throw new InvalidArgumentException('Unsupported USDT activity cursor type.');
        }

        if ($occurredAtMicros < 1 || $id < 1) {
            throw new InvalidArgumentException('USDT activity cursor values must be positive.');
        }
    }

    public static function target(int $intentId, CarbonInterface $occurredAt): self
    {
        return new self(self::TYPE_TARGET, self::timestampMicros($occurredAt), $intentId);
    }

    public static function entry(int $entryId, CarbonInterface $occurredAt): self
    {
        return new self(self::TYPE_ENTRY, self::timestampMicros($occurredAt), $entryId);
    }

    public static function fromRank(int $rank, int $occurredAtMicros, int $id): ?self
    {
        return match ($rank) {
            self::TARGET_RANK => new self(self::TYPE_TARGET, $occurredAtMicros, $id),
            self::ENTRY_RANK => new self(self::TYPE_ENTRY, $occurredAtMicros, $id),
            default => null,
        };
    }

    public function rank(): int
    {
        return $this->type === self::TYPE_TARGET
            ? self::TARGET_RANK
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
