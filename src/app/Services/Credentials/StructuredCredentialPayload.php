<?php

namespace App\Services\Credentials;

use App\Enums\CredentialAccessMethod;
use InvalidArgumentException;

final readonly class StructuredCredentialPayload
{
    public const VERSION = 1;

    public function __construct(
        public string $email,
        public string $password,
        public CredentialAccessMethod $accessMethod,
        public ?string $accessValue,
        public ?string $tutorialOverride,
        public int $version = self::VERSION,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $version = $payload['version'] ?? $payload['payload_version'] ?? null;
        if (array_key_exists('version', $payload)
            && array_key_exists('payload_version', $payload)
            && $payload['version'] !== $payload['payload_version']) {
            throw new InvalidArgumentException('Structured credential payload version is invalid.');
        }
        if (! is_int($version) || $version !== self::VERSION) {
            throw new InvalidArgumentException('Structured credential payload version is invalid.');
        }

        $email = $payload['email'] ?? null;
        $password = $payload['password'] ?? null;
        $accessMethod = $payload['access_method'] ?? null;
        $accessValue = $payload['access_value'] ?? null;
        $tutorialOverride = $payload['tutorial_override'] ?? null;

        if (! is_string($email)) {
            throw new InvalidArgumentException('Structured credential payload email is invalid.');
        }
        if (! is_string($password) || trim($password) === '') {
            throw new InvalidArgumentException('Structured credential payload password is invalid.');
        }
        if (mb_strlen($password) > 1024) {
            throw new InvalidArgumentException('Structured credential payload password is too long.');
        }
        if (! is_string($tutorialOverride) && $tutorialOverride !== null) {
            throw new InvalidArgumentException('Structured credential payload tutorial is invalid.');
        }
        if ($tutorialOverride !== null && trim($tutorialOverride) === '') {
            $tutorialOverride = null;
        }
        if ($tutorialOverride !== null && mb_strlen($tutorialOverride) > 2000) {
            throw new InvalidArgumentException('Structured credential payload tutorial is too long.');
        }

        try {
            $normalizedEmail = (new CredentialIdentityNormalizer)->normalizeEmail($email);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Structured credential payload email is invalid.');
        }
        if (mb_strlen($normalizedEmail) > 254) {
            throw new InvalidArgumentException('Structured credential payload email is too long.');
        }

        try {
            $method = $accessMethod instanceof CredentialAccessMethod
                ? $accessMethod
                : CredentialAccessMethod::from((string) $accessMethod);
        } catch (\ValueError) {
            throw new InvalidArgumentException('Structured credential payload access method is invalid.');
        }

        if ($accessValue !== null && ! is_string($accessValue)) {
            throw new InvalidArgumentException('Structured credential payload access value is invalid.');
        }
        if ($accessValue !== null && trim($accessValue) === '') {
            $accessValue = null;
        }

        match ($method) {
            CredentialAccessMethod::None => self::assertNoAccessValue($accessValue),
            CredentialAccessMethod::OtpMailLink => self::assertOtpMailLink($accessValue),
            CredentialAccessMethod::AuthToken => self::assertAuthToken($accessValue),
        };

        return new self(
            email: $normalizedEmail,
            password: $password,
            accessMethod: $method,
            accessValue: $accessValue,
            tutorialOverride: $tutorialOverride,
            version: $version,
        );
    }

    public static function fromValues(
        string $email,
        string $password,
        CredentialAccessMethod|string $accessMethod,
        ?string $accessValue = null,
        ?string $tutorialOverride = null,
    ): self {
        return self::fromArray([
            'version' => self::VERSION,
            'email' => $email,
            'password' => $password,
            'access_method' => $accessMethod,
            'access_value' => $accessValue,
            'tutorial_override' => $tutorialOverride,
        ]);
    }

    /**
     * @return array{version: int, email: string, password: string, access_method: string, access_value: ?string, tutorial_override: ?string}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'email' => $this->email,
            'password' => $this->password,
            'access_method' => $this->accessMethod->value,
            'access_value' => $this->accessValue,
            'tutorial_override' => $this->tutorialOverride,
        ];
    }

    /**
     * @return list<array{kind: string, value: string}>
     */
    public function identityValues(): array
    {
        $identities = [['kind' => CredentialIdentityKind::Email, 'value' => $this->email]];

        if ($this->accessMethod === CredentialAccessMethod::AuthToken && $this->accessValue !== null) {
            $identities[] = ['kind' => CredentialIdentityKind::AuthToken, 'value' => $this->accessValue];
        }

        return $identities;
    }

    private static function assertNoAccessValue(?string $accessValue): void
    {
        if ($accessValue !== null) {
            throw new InvalidArgumentException('Structured credential payload access value is not allowed.');
        }
    }

    private static function assertOtpMailLink(?string $accessValue): void
    {
        if ($accessValue === null || mb_strlen($accessValue) > 2048 || filter_var($accessValue, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Structured credential payload OTP Mail Link is invalid.');
        }

        $parts = parse_url($accessValue);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ($scheme !== 'https'
            || ! is_string($host)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Structured credential payload OTP Mail Link is invalid.');
        }
    }

    private static function assertAuthToken(?string $accessValue): void
    {
        if ($accessValue === null || trim($accessValue) === '') {
            throw new InvalidArgumentException('Structured credential payload Auth Token is invalid.');
        }
        if (mb_strlen($accessValue) > 3500) {
            throw new InvalidArgumentException('Structured credential payload Auth Token is too long.');
        }
    }
}
