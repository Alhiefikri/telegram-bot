<?php

namespace App\Enums;

enum StoreBalanceTopUpOutcome: string
{
    case Payable = 'payable';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Creating = 'creating';
    case RecoveryPending = 'recovery_pending';
    case ProviderTotalPending = 'provider_total_pending';
    case InvalidProviderResult = 'invalid_provider_result';
    case ProviderUnavailable = 'provider_unavailable';
    case Expired = 'expired';
}
