<?php

namespace App\Services\Credentials;

final class CredentialIdentityKind
{
    public const Email = 'email';

    public const AuthToken = 'auth_token';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::Email, self::AuthToken];
    }

    private function __construct() {}
}
