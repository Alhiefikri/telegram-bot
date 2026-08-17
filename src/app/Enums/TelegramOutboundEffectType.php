<?php

namespace App\Enums;

enum TelegramOutboundEffectType: string
{
    case ManualCheckoutInstructions = 'checkout.manual_payment_instructions';
    case KlikQrisCheckoutInstructions = 'checkout.klikqris_payment_instructions';
    case PakasirCheckoutInstructions = 'checkout.pakasir_payment_instructions';
    case BinanceCheckoutInstructions = 'checkout.binance_payment_instructions';
    case ReceiptCredentials = 'receipt.credentials';
    case AdminOrderPendingNotification = 'admin.order_pending_notification';
    case AdminOrderPaidNotification = 'admin.order_paid_notification';
    case AdminOrderExpiredNotification = 'admin.order_expired_notification';
    case ManualPaymentProof = 'admin.manual_payment_proof';
    case SalesProofPost = 'sales_proof.post';
}
