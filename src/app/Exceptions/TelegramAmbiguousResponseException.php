<?php

namespace App\Exceptions;

use RuntimeException;

final class TelegramAmbiguousResponseException extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            $operation.' returned ok=true without a valid message ID; a positive integer is required, but the delivery outcome is unknown.',
        );
    }
}
