<?php

namespace App\Enums;

enum PaymentEventType: string
{
    case Callback = 'callback';
    case ProviderCreate = 'provider_create';
    case Reconciliation = 'reconciliation';
    case ManualUpdate = 'manual_update';
}
