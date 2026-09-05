<?php

namespace App\Services\Telegram;

use InvalidArgumentException;
use LogicException;

/**
 * One in-memory Telegram document send. Document bytes and the filename must
 * never cross a queue, cache, log, or persistence boundary.
 */
final readonly class TelegramDocumentPayload
{
    public const MAX_BYTES = 1_048_576;

    public function __construct(
        private string $documentContents,
        private string $filename,
    ) {
        if ($this->documentContents === '') {
            throw new InvalidArgumentException('Telegram document payload cannot be empty.');
        }

        if (! mb_check_encoding($this->documentContents, 'UTF-8')) {
            throw new InvalidArgumentException('Telegram document payload must be valid UTF-8.');
        }

        if (str_starts_with($this->documentContents, "\xEF\xBB\xBF")) {
            throw new InvalidArgumentException('Telegram document payload must not contain a BOM.');
        }

        if (str_contains($this->documentContents, "\r")) {
            throw new InvalidArgumentException('Telegram document payload must use LF line endings.');
        }

        if (strlen($this->documentContents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Telegram document payload exceeds the configured size limit.');
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.txt\z/D', $this->filename) !== 1
            || str_contains($this->filename, '..')) {
            throw new InvalidArgumentException('Telegram document filename is invalid.');
        }
    }

    public function documentContents(): string
    {
        return $this->documentContents;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function byteLength(): int
    {
        return strlen($this->documentContents);
    }

    public function __serialize(): array
    {
        throw new LogicException('Telegram document payloads cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new LogicException('Telegram document payloads cannot be unserialized.');
    }

    /** @return array{byte_length: int, format: string} */
    public function __debugInfo(): array
    {
        return [
            'byte_length' => $this->byteLength(),
            'format' => 'txt',
        ];
    }
}
