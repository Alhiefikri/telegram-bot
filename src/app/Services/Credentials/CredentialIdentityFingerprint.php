<?php

namespace App\Services\Credentials;

use RuntimeException;

final class CredentialIdentityFingerprint
{
    private const GENERATED_KEY_BYTES = 32;

    public function __construct(private readonly ?string $key = null) {}

    public function isReady(): bool
    {
        return trim($this->configuredKey()) !== '';
    }

    /**
     * Ensure a stable local key exists when the optional environment override
     * is absent. The file lives on the persistent application storage volume,
     * so Account Input does not need a manual ENV toggle to become usable.
     */
    public function ensureKey(): void
    {
        $configured = $this->configuredEnvironmentKey();
        $persisted = $this->readPersistedKey();

        if ($configured !== '') {
            if ($persisted !== null && ! hash_equals($persisted, $configured)) {
                throw new RuntimeException('Credential identity fingerprint key changed while a persisted key exists.');
            }
            if ($persisted === null && ! app()->environment('testing')) {
                $this->persistKey($configured, requireSameExisting: true);
                $persisted = $this->readPersistedKey();
                if ($persisted === null || ! hash_equals($persisted, $configured)) {
                    throw new RuntimeException('Credential identity fingerprint key changed while it was being persisted.');
                }
            }

            return;
        }

        if ($persisted !== null) {
            return;
        }

        $this->persistKey(bin2hex(random_bytes(self::GENERATED_KEY_BYTES)));
    }

    private function persistKey(string $key, bool $requireSameExisting = false): void
    {
        $path = $this->keyPath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Credential identity fingerprint key storage is unavailable.');
        }

        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            try {
                if (fwrite($handle, $key."\n") === false) {
                    throw new RuntimeException('Credential identity fingerprint key could not be persisted.');
                }
                fflush($handle);
                @chmod($path, 0600);
            } finally {
                fclose($handle);
            }

            return;
        }

        // Another worker may have won the first-use race. Accept its key only
        // after reading it back; never replace an existing identity key.
        $persisted = $this->readPersistedKey();
        if ($persisted !== null && (! $requireSameExisting || hash_equals($persisted, $key))) {
            return;
        }

        throw new RuntimeException('Credential identity fingerprint key could not be initialized.');
    }

    /**
     * Return a non-secret identifier for the configured fingerprint key.
     *
     * The registry readiness record uses this digest to prevent a successful
     * backfill performed with one key from authorizing intake under another.
     * The key itself is never returned or exposed outside its dedicated
     * environment/storage location.
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
        $configured = $this->configuredEnvironmentKey();

        return $configured !== '' ? $configured : (string) ($this->readPersistedKey() ?? '');
    }

    private function configuredEnvironmentKey(): string
    {
        return trim($this->key ?? (string) config('services.credentials.identity_fingerprint_key', ''));
    }

    private function keyPath(): string
    {
        return (string) config(
            'services.credentials.identity_fingerprint_key_path',
            storage_path('app/private/credential-identity-fingerprint.key'),
        );
    }

    private function readPersistedKey(): ?string
    {
        $path = $this->keyPath();
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('Credential identity fingerprint key storage is unreadable.');
        }

        return trim($contents);
    }
}
