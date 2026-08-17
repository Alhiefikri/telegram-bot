<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A checkout instruction failed before Telegram was called.
 *
 * Provider recovery and render failures are retryable effect failures; they
 * must not be confused with an accepted-then-disconnected Telegram request.
 */
final class TelegramOutboundPreSendException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
