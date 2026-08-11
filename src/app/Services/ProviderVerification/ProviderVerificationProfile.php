<?php

namespace App\Services\ProviderVerification;

enum ProviderVerificationProfile: string
{
    case Interactive = 'interactive';
    case Webhook = 'webhook';
    case Reconciliation = 'reconciliation';
}
