<?php

namespace App\Enums;

enum PaymentEventType: string
{
    case Callback = 'callback';
    case Reconciliation = 'reconciliation';
    case ManualUpdate = 'manual_update';
}
