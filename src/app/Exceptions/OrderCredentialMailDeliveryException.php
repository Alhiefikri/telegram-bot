<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a credential email attempt failed and the durable row still
 * holds retry budget. It never carries provider transport details.
 */
class OrderCredentialMailDeliveryException extends RuntimeException
{
    public function __construct(string $message = 'Credential email delivery failed.')
    {
        parent::__construct($message);
    }
}
