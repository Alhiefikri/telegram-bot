<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahTransportException extends SakurupiahProviderException
{
    public function __construct(
        string $operation,
        bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Sakurupiah '.$operation.' request failed before a provider response was received.',
            reason: 'transport',
            retryable: ! $outcomeUnknown,
            outcomeUnknown: $outcomeUnknown,
            operation: $operation,
            previous: $previous,
        );
    }
}
