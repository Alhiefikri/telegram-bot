<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahSchemaException extends SakurupiahProviderException
{
    public function __construct(
        string $operation = 'response',
        bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Sakurupiah '.$operation.' response schema is invalid.',
            reason: 'schema',
            outcomeUnknown: $outcomeUnknown,
            operation: $operation,
            previous: $previous,
        );
    }
}
