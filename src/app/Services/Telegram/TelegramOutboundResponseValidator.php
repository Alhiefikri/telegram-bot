<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramAmbiguousResponseException;
use RuntimeException;

final class TelegramOutboundResponseValidator
{
    /**
     * Extract the only response value that can prove a durable Telegram
     * delivery succeeded.
     *
     * A successful Telegram response without a positive message identifier
     * proves neither delivery nor rejection: the request may already have been
     * accepted. Callers must classify that shape as an ambiguous delivery
     * outcome instead of a definite failure that may be retried, so no
     * already-accepted message is blindly resent.
     */
    public function messageIdFromResponse(mixed $response, string $operation = 'Telegram delivery'): string
    {
        $messageId = is_array($response)
            ? data_get($response, 'result.message_id')
            : null;

        if (! is_array($response) || data_get($response, 'ok') !== true) {
            throw new RuntimeException(
                $operation.' did not return a successful message ID; a positive integer is required.',
            );
        }

        if (! $this->isValidMessageId($messageId)) {
            throw new TelegramAmbiguousResponseException($operation);
        }

        return (string) $messageId;
    }

    public function isValidMessageId(mixed $messageId): bool
    {
        return (is_int($messageId) && $messageId > 0)
            || (is_string($messageId) && preg_match('/\A0*[1-9]\d*\z/', $messageId) === 1);
    }
}
