<?php

namespace App\Services\Sakurupiah;

use Throwable;

final class SakurupiahApplicationException extends SakurupiahProviderException
{
    public function __construct(
        string $operation,
        string $providerStatus,
        bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Sakurupiah '.$operation.' returned provider application status '.$providerStatus.'.',
            reason: 'application',
            retryable: false,
            outcomeUnknown: $outcomeUnknown,
            operation: $operation,
            previous: $previous,
        );

        $this->providerStatus = $providerStatus;
    }

    public readonly string $providerStatus;
}
