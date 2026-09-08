<?php

namespace App\Services\Credentials;

use App\Enums\CredentialAccessMethod;
use LogicException;

/**
 * One credential unit prepared for an already-authorized paid delivery.
 *
 * Secret-bearing values intentionally stay private. The accessors are for
 * rendering at the channel boundary; the object cannot be serialized or
 * debug-dumped with its contents.
 */
final readonly class CredentialDeliveryAccount
{
    /**
     * @param  ?array{email: string, password: string, mfa: ?string}  $legacyParts
     */
    public function __construct(
        private int $number,
        private bool $legacy,
        private string $text,
        private ?array $legacyParts,
        private ?string $email,
        private ?string $password,
        private ?CredentialAccessMethod $accessMethod,
        private ?string $accessValue,
        private ?string $tutorial,
        private ?string $readerMailLink = null,
    ) {}

    public function number(): int
    {
        return $this->number;
    }

    public function accountNumber(): int
    {
        return $this->number();
    }

    public function label(): string
    {
        return 'Data akun '.$this->number;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function formattedText(): string
    {
        return $this->text();
    }

    /**
     * @return ?array{email: string, password: string, mfa: ?string}
     */
    public function legacyParts(): ?array
    {
        return $this->legacyParts;
    }

    /**
     * @return ?array{email: string, password: string, mfa: ?string}
     */
    public function parts(): ?array
    {
        return $this->legacyParts();
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function accessMethod(): ?CredentialAccessMethod
    {
        return $this->accessMethod;
    }

    public function accessValue(): ?string
    {
        return $this->accessValue;
    }

    public function readerMailLink(): ?string
    {
        return $this->readerMailLink;
    }

    public function tutorial(): ?string
    {
        return $this->tutorial;
    }

    public function isLegacy(): bool
    {
        return $this->legacy;
    }

    public function isStructured(): bool
    {
        return ! $this->legacy;
    }

    public function __serialize(): array
    {
        throw new LogicException('Credential delivery values cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Credential delivery values cannot be unserialized.');
    }

    /** @return array{account_number: int, structured: bool, has_tutorial: bool, access_method: ?string} */
    public function __debugInfo(): array
    {
        return [
            'account_number' => $this->number,
            'structured' => $this->isStructured(),
            'has_tutorial' => $this->tutorial !== null,
            'access_method' => $this->accessMethod?->value,
        ];
    }
}
