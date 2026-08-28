<?php

namespace App\Services\Admin;

final readonly class BscReceiverMutationResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
