<?php

namespace App\Services\Credentials;

use App\Services\CredentialDeliveryFormatter;

final class LegacyCredentialIdentityParser
{
    public function __construct(private readonly CredentialIdentityNormalizer $normalizer) {}

    /**
     * Return only identities that the legacy formatter can recognize without
     * guessing whether its third field is an OTP value or an Auth Token.
     *
     * @return list<array{kind: string, value: string}>
     */
    public function extract(string $secret): array
    {
        $parts = (new CredentialDeliveryFormatter)->parts($secret);
        if ($parts === null) {
            return [];
        }

        return [[
            'kind' => CredentialIdentityKind::Email,
            'value' => $this->normalizer->normalizeEmail($parts['email']),
        ]];
    }
}
