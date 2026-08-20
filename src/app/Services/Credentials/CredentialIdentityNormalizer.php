<?php

namespace App\Services\Credentials;

use InvalidArgumentException;

final class CredentialIdentityNormalizer
{
    public function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Credential email is invalid.');
        }

        return $normalized;
    }

    /**
     * Auth Tokens are identity values exactly as supplied. Only blank values
     * are rejected; trimming here would make two distinct tokens collide.
     */
    public function normalizeAuthToken(string $token): string
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Credential Auth Token is empty.');
        }

        return $token;
    }

    public function normalize(string $kind, string $value): string
    {
        return match ($kind) {
            CredentialIdentityKind::Email => $this->normalizeEmail($value),
            CredentialIdentityKind::AuthToken => $this->normalizeAuthToken($value),
            default => throw new InvalidArgumentException('Credential identity kind is invalid.'),
        };
    }
}
