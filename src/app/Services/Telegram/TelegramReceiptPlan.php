<?php

namespace App\Services\Telegram;

use InvalidArgumentException;
use LogicException;

/**
 * One in-memory buyer receipt plan. It contains plaintext only for the current
 * render attempt and is deliberately unusable as a queue or persistence DTO.
 */
final readonly class TelegramReceiptPlan
{
    public const PHOTO_CAPTION_LIMIT = 1024;

    public const MESSAGE_TEXT_LIMIT = 4096;

    /**
     * @param  list<TelegramMessageUnit>  $photoUnits
     * @param  list<TelegramMessageUnit>  $textUnits
     * @param  list<array{title: string, url: string}>  $guides
     */
    public function __construct(
        private array $photoUnits,
        private array $textUnits,
        private int $productCount,
        private int $accountCount,
        private array $guides = [],
        private bool $documentDelivery = false,
    ) {
        if ($this->photoUnits === [] || $this->textUnits === []) {
            throw new InvalidArgumentException('Telegram receipt plan must contain deliverable units.');
        }

        if ($this->photoUnits[0]->characterLength() > self::PHOTO_CAPTION_LIMIT) {
            throw new InvalidArgumentException('Telegram receipt header exceeds the photo caption limit.');
        }

        foreach ($this->photoUnits as $unit) {
            if ($unit->characterLength() > self::MESSAGE_TEXT_LIMIT) {
                throw new InvalidArgumentException('Telegram receipt photo plan contains an oversized unit.');
            }
        }

        foreach ($this->textUnits as $unit) {
            if ($unit->characterLength() < 1 || $unit->characterLength() > self::MESSAGE_TEXT_LIMIT) {
                throw new InvalidArgumentException('Telegram receipt text plan contains an invalid unit.');
            }
        }
    }

    public function usesDocument(): bool
    {
        return $this->documentDelivery;
    }

    public function photoUnitCount(): int
    {
        return count($this->photoUnits);
    }

    public function textUnitCount(): int
    {
        return count($this->textUnits);
    }

    public function productCount(): int
    {
        return $this->productCount;
    }

    public function accountCount(): int
    {
        return $this->accountCount;
    }

    /** @return list<TelegramMessageUnit> */
    public function photoUnits(): array
    {
        return $this->photoUnits;
    }

    /** @return list<TelegramMessageUnit> */
    public function textUnits(): array
    {
        return $this->textUnits;
    }

    public function photoUnit(int $sequence): TelegramMessageUnit
    {
        return $this->unitAt($this->photoUnits, $sequence);
    }

    public function textUnit(int $sequence): TelegramMessageUnit
    {
        return $this->unitAt($this->textUnits, $sequence);
    }

    /** @return list<array{title: string, url: string}> */
    public function guides(): array
    {
        return $this->guides;
    }

    public function __serialize(): array
    {
        throw new LogicException('Telegram receipt plans cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new LogicException('Telegram receipt plans cannot be unserialized.');
    }

    /** @return array{product_count: int, account_count: int, photo_chunk_count: int, text_chunk_count: int} */
    public function __debugInfo(): array
    {
        return [
            'product_count' => $this->productCount,
            'account_count' => $this->accountCount,
            'photo_chunk_count' => $this->photoUnitCount(),
            'text_chunk_count' => $this->textUnitCount(),
        ];
    }

    /** @param list<TelegramMessageUnit> $units */
    private function unitAt(array $units, int $sequence): TelegramMessageUnit
    {
        $unit = $units[$sequence - 1] ?? null;
        if (! $unit instanceof TelegramMessageUnit) {
            throw new InvalidArgumentException('Telegram receipt sequence is no longer renderable.');
        }

        return $unit;
    }

    private function lengthBucket(int $length): string
    {
        return match (true) {
            $length <= 1024 => '0-1024',
            $length <= 2048 => '1025-2048',
            $length <= 3072 => '2049-3072',
            default => '3073-4096',
        };
    }
}
