<?php

namespace App\Services\Admin;

final readonly class AdminLoginThrottleDecision
{
    public function __construct(
        public bool $blocked,
        public int $identityAttempts,
        public int $ipAttempts,
        public int $retryAfter,
    ) {}
}
