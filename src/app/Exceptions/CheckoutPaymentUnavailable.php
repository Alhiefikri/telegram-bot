<?php

namespace App\Exceptions;

final class CheckoutPaymentUnavailable extends InvalidCheckout
{
    public function __construct(
        string $message,
        public readonly string $reason = 'configuration_invalid',
        public readonly ?int $retryAt = null,
        public readonly ?string $channel = null,
        public readonly ?int $observationAgeSeconds = null,
    ) {
        parent::__construct($message);
    }
}
