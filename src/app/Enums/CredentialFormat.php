<?php

namespace App\Enums;

enum CredentialFormat: string
{
    case Legacy = 'legacy';
    case StructuredAccount = 'structured_account';
}
