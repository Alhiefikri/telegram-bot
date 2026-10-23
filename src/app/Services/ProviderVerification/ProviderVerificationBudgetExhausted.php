<?php

namespace App\Services\ProviderVerification;

use RuntimeException;

final class ProviderVerificationBudgetExhausted extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Provider verification stopped: '.$reason.'.');
    }
}
