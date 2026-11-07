<?php

namespace App\Services\WijayaPay;

final class WijayaPayApplicationException extends WijayaPayProviderException
{
    public function __construct(
        string $operation,
        ?string $providerStatus = null,
        ?string $providerMessage = null,
        bool $outcomeUnknown = false,
    ) {
        parent::__construct(
            'WijayaPay rejected the '.$operation.' request.',
            operation: $operation,
            providerStatus: $providerStatus,
            providerMessage: $providerMessage,
            outcomeUnknown: $outcomeUnknown,
            reason: 'application_rejection',
        );
    }
}
