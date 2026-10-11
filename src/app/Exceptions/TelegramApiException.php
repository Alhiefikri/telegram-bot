<?php

namespace App\Exceptions;

use App\Services\Telegram\TelegramBotApiRedactor;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

final class TelegramApiException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly ?int $status,
        public readonly ?string $description = null,
    ) {
        $statusText = $status === null ? '' : " (HTTP {$status})";

        parent::__construct("Telegram Bot API {$operation} failed{$statusText}.");
    }

    public static function fromRequest(string $operation, RequestException $exception): self
    {
        return new self(
            operation: $operation,
            status: $exception->response?->status(),
            description: self::descriptionFromResponse($exception->response?->json()),
        );
    }

    public static function fromResponse(string $operation, mixed $response): self
    {
        return new self(
            operation: $operation,
            status: null,
            description: self::descriptionFromResponse($response),
        );
    }

    public function isMessageNotModified(): bool
    {
        return str_contains($this->normalizedDescription(), 'message is not modified');
    }

    public function isCustomEmojiRejected(): bool
    {
        if ($this->status !== 400) {
            return false;
        }

        $description = $this->normalizedDescription();

        return str_contains($description, 'custom emoji')
            || str_contains($description, 'icon_custom_emoji_id')
            || str_contains($description, 'tg-emoji');
    }

    public function isDefiniteEditFailure(): bool
    {
        if ($this->status !== 400) {
            return false;
        }

        foreach ([
            'message to edit not found',
            "message can't be edited",
            'message is too old',
            'there is no text in the message to edit',
            'there is no caption in the message to edit',
        ] as $phrase) {
            if (str_contains($this->normalizedDescription(), $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedDescription(): string
    {
        return strtolower((string) $this->description);
    }

    private static function descriptionFromResponse(mixed $response): ?string
    {
        $description = is_array($response) ? ($response['description'] ?? null) : null;

        return is_string($description) ? TelegramBotApiRedactor::redactText($description) : null;
    }
}
