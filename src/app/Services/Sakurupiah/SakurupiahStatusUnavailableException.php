<?php

namespace App\Services\Sakurupiah;

use RuntimeException;
use Throwable;

final class SakurupiahStatusUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Sakurupiah payment status could not be verified.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
