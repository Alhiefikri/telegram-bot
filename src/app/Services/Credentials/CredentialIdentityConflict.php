<?php

namespace App\Services\Credentials;

use RuntimeException;

final class CredentialIdentityConflict extends RuntimeException
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $identityKind,
        public readonly ?int $existingStockItemId,
    ) {
        parent::__construct('Credential identity is already registered.');
    }
}
