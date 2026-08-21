<?php

namespace App\Services\Telegram;

use LogicException;

/**
 * One in-memory Telegram send unit. Credential-bearing text and OTP URLs are
 * intentionally kept out of durable effect rows and cannot be serialized.
 */
readonly class TelegramMessageUnit
{
    /**
     * @param  list<array{type: string, offset: int, length: int}>  $entities
     * @param  array<string, mixed>  $replyMarkup
     */
    public function __construct(
        private string $text,
        private array $entities = [],
        private array $replyMarkup = [],
    ) {}

    public function text(): string
    {
        return $this->text;
    }

    /** @return list<array{type: string, offset: int, length: int}> */
    public function entities(): array
    {
        return $this->entities;
    }

    /** @return array<string, mixed> */
    public function replyMarkup(): array
    {
        return $this->replyMarkup;
    }

    public function characterLength(): int
    {
        return mb_strlen($this->text, 'UTF-8');
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->entities === [] ? [] : ['entities' => $this->entities];
    }

    public function __serialize(): array
    {
        throw new LogicException('Telegram message units cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Telegram message units cannot be unserialized.');
    }

    /** @return array{character_length: int, entity_count: int, has_reply_markup: bool} */
    public function __debugInfo(): array
    {
        return [
            'character_length' => $this->characterLength(),
            'entity_count' => count($this->entities),
            'has_reply_markup' => $this->replyMarkup !== [],
        ];
    }
}
