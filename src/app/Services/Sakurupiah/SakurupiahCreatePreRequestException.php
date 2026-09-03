<?php

namespace App\Services\Sakurupiah;

use RuntimeException;
use Throwable;

final class SakurupiahCreatePreRequestException extends RuntimeException
{
    public function __construct(
        string $message = 'Sakurupiah invoice creation was not sent because the provider is not ready.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
