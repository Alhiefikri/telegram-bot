<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TelegramTransportException extends RuntimeException
{
    public function __construct(string $operation, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Telegram Bot API %s request failed: %s.', $operation, $previous ? class_basename($previous) : 'transport error'),
        );
    }
}
