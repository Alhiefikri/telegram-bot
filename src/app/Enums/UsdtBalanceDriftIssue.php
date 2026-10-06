<?php

namespace App\Enums;

/**
 * One proven divergence between a Customer USDT Balance projection/cursor and
 * its immutable signed ledger. A finding freezes the scoped account; it never
 * repairs or rewrites money.
 */
enum UsdtBalanceDriftIssue: string
{
    case BalanceMismatch = 'balance_mismatch';
    case CursorMismatch = 'cursor_mismatch';
    case HistoryGap = 'history_gap';
}
