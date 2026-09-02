<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahJsonException extends SakurupiahProviderException
{
    public function __construct(
        string $operation,
        bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Sakurupiah '.$operation.' response was not valid JSON.',
            reason: 'json',
            outcomeUnknown: $outcomeUnknown,
            operation: $operation,
            previous: $previous,
        );
    }
}
