<?php

namespace App\Services\Sakurupiah;

/**
 * Safe states exposed by the Sakurupiah invoice lifecycle.
 *
 * Provider payloads and the internal provider_create_state values never cross
 * this boundary. Only Payable carries presentation data.
 */
enum SakurupiahInvoiceOutcome: string
{
    case Payable = 'payable';

    case Creating = 'creating';

    case RecoveryPending = 'recovery_pending';

    case ProviderTotalPending = 'provider_total_pending';

    /** The provider response exists, but no safe payment presentation is available yet. */
    case ProviderPresentationPending = 'provider_presentation_pending';

    case InvalidProviderResult = 'invalid_provider_result';

    case Expired = 'expired';
}
