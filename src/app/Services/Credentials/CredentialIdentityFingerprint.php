<?php

namespace App\Services\Credentials;

use RuntimeException;

final class CredentialIdentityFingerprint
{
    public function __construct(private readonly ?string $key = null) {}

    public function isReady(): bool
    {
        return trim($this->configuredKey()) !== '';
    }

    public function assertReady(): void
    {
        if (! $this->isReady()) {
            throw new RuntimeException('Credential identity fingerprint key is not configured.');
        }
    }

    public function forEmail(string $email): string
    {
        return $this->forIdentity(CredentialIdentityKind::Email, $email);
    }

    public function forAuthToken(string $token): string
    {
        return $this->forIdentity(CredentialIdentityKind::AuthToken, $token);
    }

    public function forIdentity(string $kind, string $value): string
    {
        $this->assertReady();

        $normalized = (new CredentialIdentityNormalizer)->normalize($kind, $value);

        return hash_hmac('sha256', $normalized, $this->configuredKey());
    }

    private function configuredKey(): string
    {
        return $this->key ?? (string) config('services.credentials.identity_fingerprint_key', '');
    }
}
