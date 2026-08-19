<?php

namespace App\Services\Telegram;

use RuntimeException;

final class TelegramOutboundResponseValidator
{
    /**
     * Extract the only response value that can prove a durable Telegram
     * delivery succeeded.
     */
    public function messageIdFromResponse(mixed $response, string $operation = 'Telegram delivery'): string
    {
        $messageId = is_array($response)
            ? data_get($response, 'result.message_id')
            : null;

        if (! is_array($response)
            || data_get($response, 'ok') !== true
            || ! $this->isValidMessageId($messageId)) {
            throw new RuntimeException(
                $operation.' did not return a successful message ID; a positive integer is required.',
            );
        }

        return (string) $messageId;
    }

    public function isValidMessageId(mixed $messageId): bool
    {
        return (is_int($messageId) && $messageId > 0)
            || (is_string($messageId) && preg_match('/\A0*[1-9]\d*\z/', $messageId) === 1);
    }
}
