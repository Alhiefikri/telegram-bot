<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahReadinessException extends SakurupiahProviderException
{
    public function __construct(
        string $message = 'Sakurupiah provider is not ready.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            reason: 'readiness',
            operation: 'readiness',
            previous: $previous,
        );
    }
}
