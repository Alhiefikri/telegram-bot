<?php

namespace App\Enums;

/**
 * Divergence between an external USDT funding receipt and the USDT subledger
 * posting (or its absence). Differences stay visible and exact; nothing is
 * rounded, repaired or credited by reconciliation.
 */
enum UsdtReceiptFindingKind: string
{
    case OrphanClaimedReceipt = 'orphan_claimed_receipt';
    case HeldReceipt = 'held_receipt';
    case CreditWithoutReceipt = 'credit_without_receipt';
    case CurrencyMismatch = 'currency_mismatch';
    case ReceiptAmountUnrepresentable = 'receipt_amount_unrepresentable';
    case UnsettledDifference = 'unsettled_difference';
    case OvercreditedReceipt = 'overcredited_receipt';
}
