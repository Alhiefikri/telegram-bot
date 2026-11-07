<?php

namespace App\Services\WijayaPay;

enum WijayaPayInvoiceOutcome: string
{
    case Payable = 'payable';
    case Creating = 'creating';
    case RecoveryPending = 'recovery_pending';
    case ProviderTotalPending = 'provider_total_pending';
    case ProviderPresentationPending = 'provider_presentation_pending';
    case InvalidProviderResult = 'invalid_provider_result';
    case Expired = 'expired';
    case Paid = 'paid';
}
