<?php

namespace App\Enums;

enum StoreBalanceTopUpStatus: string
{
    case Pending = 'pending';
    case Payable = 'payable';
    case Paid = 'paid';
    case Failed = 'failed';
    case RecoveryPending = 'recovery_pending';
    case InvalidProviderResult = 'invalid_provider_result';
    case Expired = 'expired';
    case Compensated = 'compensated';
}
