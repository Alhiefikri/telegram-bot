<?php

namespace App\Services\KlikQris;

/**
 * The only outcomes the KlikQRIS Native QRIS lifecycle hands to callers.
 *
 * Raw provider payloads and persistence-state strings are implementation
 * details and never cross this boundary.
 */
enum KlikQrisNativeQrisOutcome: string
{
    /** The QR is presentation-safe and the provider final total and effective deadline are valid. */
    case Payable = 'payable';

    /** A create attempt is in flight or can be retried by opening the invoice again. Nothing is payable yet. */
    case Creating = 'creating';

    /** An earlier create result was uncertain and is being recovered on the same Payment. Retry on the same invoice. */
    case RecoveryPending = 'recovery_pending';

    /** The provider has not yet supplied a final payable total; the QR must not be paid. */
    case ProviderTotalPending = 'provider_total_pending';

    /** The provider result is missing, invalid, or untrusted; the QR must not be paid. */
    case InvalidProviderResult = 'invalid_provider_result';

    /** The effective deadline (Order or provider) has passed; the QR must not be paid. */
    case Expired = 'expired';
}
