<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramAmbiguousResponseException;
use RuntimeException;

final class TelegramOutboundResponseValidator
{
    /**
     * Extract the only response value that can prove a durable Telegram
     * delivery succeeded.
     */
    public function messageIdFromResponse(mixed $response, string $operation = 'Telegram delivery'): string
    {
        return $this->validatedMessageId($response, $operation, false);
    }

    /**
     * A successful document upload without a positive message ID cannot prove
     * that Telegram did not accept the file, so the caller must treat it as
     * an ambiguous delivery outcome.
     */
    public function messageIdFromDocumentResponse(
        mixed $response,
        string $operation = 'Telegram document delivery',
    ): string {
        return $this->validatedMessageId($response, $operation, true);
    }

    public function isValidMessageId(mixed $messageId): bool
    {
        return (is_int($messageId) && $messageId > 0)
            || (is_string($messageId) && preg_match('/\A0*[1-9]\d*\z/', $messageId) === 1);
    }

    private function validatedMessageId(
        mixed $response,
        string $operation,
        bool $successfulResponseWithoutMessageIdIsAmbiguous,
    ): string {
        $messageId = is_array($response)
            ? data_get($response, 'result.message_id')
            : null;

        if (! is_array($response) || data_get($response, 'ok') !== true) {
            throw new RuntimeException(
                $operation.' did not return a successful message ID; a positive integer is required.',
            );
        }

        if (! $this->isValidMessageId($messageId)) {
            if ($successfulResponseWithoutMessageIdIsAmbiguous) {
                throw new TelegramAmbiguousResponseException($operation);
            }

            throw new RuntimeException(
                $operation.' did not return a successful message ID; a positive integer is required.',
            );
        }

        return (string) $messageId;
    }
}
