<?php

namespace App\Services\CustomerApi;

/**
 * One bounded read at the HTTP boundary. `unavailable` means the durable row
 * could not be read (the request must fail closed with the existing
 * temporary-unavailable path), while a missing row is ordinary OFF state.
 */
final readonly class CustomerApiAvailabilityCheck
{
    private function __construct(
        public bool $enabled,
        public bool $unavailable,
    ) {}

    public static function enabled(): self
    {
        return new self(true, false);
    }

    public static function maintenance(): self
    {
        return new self(false, false);
    }

    public static function unavailable(): self
    {
        return new self(false, true);
    }
}
