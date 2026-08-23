<?php

namespace App\Services\Credentials;

use App\Services\CredentialDeliveryFormatter;

final class LegacyCredentialIdentityParser
{
    /**
     * Validate the legacy shape without treating its email as a reusable
     * identity. Legacy credentials predate the registry and their third field
     * is intentionally not interpreted as an Auth Token.
     *
     * @return list<array{kind: string, value: string}>
     */
    public function extract(string $secret): array
    {
        $parts = (new CredentialDeliveryFormatter)->parts($secret);
        if ($parts === null) {
            return [];
        }

        return [];
    }
}
