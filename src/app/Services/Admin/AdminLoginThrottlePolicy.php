<?php

namespace App\Services\Admin;

final readonly class AdminLoginThrottlePolicy
{
    public function __construct(
        public int $identityMaxAttempts,
        public int $identityDecaySeconds,
        public int $ipMaxAttempts,
        public int $ipDecaySeconds,
    ) {}
}
