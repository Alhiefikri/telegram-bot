<?php

namespace App\Services\CustomerApi;

use LogicException;

final class IssuedCustomerApiKey implements \JsonSerializable
{
    public function __construct(
        public readonly string $maskedIdentifier,
        #[\SensitiveParameter] private ?string $secret,
    ) {}

    public function takeSecret(): string
    {
        if ($this->secret === null) {
            throw new LogicException('API secret has already been consumed. Rotate to display another.');
        }
        $secret = $this->secret;
        $this->secret = null;

        return $secret;
    }

    public function jsonSerialize(): array
    {
        return ['masked_identifier' => $this->maskedIdentifier];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('API secret results cannot be persisted or queued.');
    }

    private function __clone() {}
}
