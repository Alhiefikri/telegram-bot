<?php

namespace App\Enums;

enum PaymentEventType: string
{
    case Callback = 'callback';
    case ProviderCreate = 'provider_create';
    case ProviderCancel = 'provider_cancel';
    case Reconciliation = 'reconciliation';
    case ManualUpdate = 'manual_update';
    case OwnerAlert = 'owner_alert';
}
