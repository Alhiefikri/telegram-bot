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

    /**
     * Return a non-secret identifier for the configured fingerprint key.
     *
     * The registry readiness record uses this digest to prevent a successful
     * backfill performed with one key from authorizing intake under another.
     * The key itself is never returned or persisted.
     */
    public function keyDigest(): ?string
    {
        $key = $this->configuredKey();

        return trim($key) === '' ? null : hash('sha256', $key);
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
