<?php

namespace App\Services\Customer;

use Illuminate\Contracts\Hashing\Hasher;

final class CustomerPasswordHasher
{
    public const SCHEME = 'sha384-v1';

    public function __construct(private readonly Hasher $hasher) {}

    public function make(#[\SensitiveParameter] string $password): string
    {
        return $this->hasher->make($this->input($password));
    }

    public function check(#[\SensitiveParameter] string $password, string $hash, string $scheme): bool
    {
        if ($scheme === self::SCHEME) {
            return $this->hasher->check($this->input($password), $hash);
        }
        if ($scheme !== 'raw' || (str_starts_with($hash, '$2') && strlen($password) > 72)) {
            $this->make($password);

            return false;
        }

        return $this->hasher->check($password, $hash);
    }

    public function needsRehash(string $hash, string $scheme): bool
    {
        return $scheme !== self::SCHEME || $this->hasher->needsRehash($hash);
    }

    private function input(#[\SensitiveParameter] string $password): string
    {
        // Bcrypt accepts at most 72 bytes; the versioned prehash retains every password character.
        return base64_encode(hash('sha384', "customer-password-v1\0".$password, true));
    }
}
