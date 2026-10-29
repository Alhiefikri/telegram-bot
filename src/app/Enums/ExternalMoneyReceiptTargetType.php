<?php

namespace App\Enums;

enum ExternalMoneyReceiptTargetType: string
{
    case OrderPayment = 'order_payment';
    case StoreBalanceTopUp = 'store_balance_top_up';
    case BalanceAdjustment = 'balance_adjustment';

    /**
     * One Customer USDT top-up target. The target id is the top-up id, which
     * is also the `customer_usdt_top_up` USDT subledger source id, so a
     * claimed funding receipt and its settled ledger entry share one
     * canonical economic identity.
     */
    case CustomerUsdtTopUp = 'customer_usdt_top_up';

    case CustomerUsdtRecovery = 'customer_usdt_recovery';
}
