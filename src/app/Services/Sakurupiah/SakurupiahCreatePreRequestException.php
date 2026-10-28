<?php

namespace App\Services\Sakurupiah;

use RuntimeException;
use Throwable;

final class SakurupiahCreatePreRequestException extends RuntimeException
{
    public function __construct(
        string $message = 'Sakurupiah invoice creation was not sent because the provider is not ready.',
        ?Throwable $previous = null,
        public readonly string $reason = 'configuration_invalid',
        public readonly ?int $retryAt = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
