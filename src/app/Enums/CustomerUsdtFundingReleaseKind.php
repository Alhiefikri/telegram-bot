<?php

namespace App\Enums;

/**
 * Typed reason a USDT funding reservation may be released without a ledger
 * credit. Every kind is a claim made by a downstream verified process (rail,
 * settlement or exception review) that the pending exposure is economically
 * gone; the reservation service records the claim identity but never verifies
 * the external money itself.
 */
enum CustomerUsdtFundingReleaseKind: string
{
    /** The target was never shown to the Customer and no provider order exists. */
    case NeverExposed = 'never_exposed';

    /** The provider authoritatively declined or cancelled before any payment. */
    case ProviderDeclined = 'provider_declined';

    /** Observed funds were returned externally to the sender. */
    case ExternallyReturned = 'externally_returned';

    /** An owner-approved compensation resolved the ambiguous money. */
    case Compensated = 'compensated';

    public function label(): string
    {
        return match ($this) {
            self::NeverExposed => 'Target tidak pernah diberikan',
            self::ProviderDeclined => 'Provider menolak sebelum pembayaran',
            self::ExternallyReturned => 'Dana dikembalikan ke pengirim',
            self::Compensated => 'Diselesaikan lewat kompensasi',
        };
    }
}
