<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahApplicationException extends SakurupiahProviderException
{
    public function __construct(
        string $operation,
        string $providerStatus,
        bool $outcomeUnknown = false,
        ?string $providerMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Sakurupiah '.$operation.' returned provider application status '.$providerStatus.'.',
            reason: 'application',
            retryable: false,
            outcomeUnknown: $outcomeUnknown,
            operation: $operation,
            providerStatus: $providerStatus,
            providerMessage: $providerMessage,
            previous: $previous,
        );
    }
}
