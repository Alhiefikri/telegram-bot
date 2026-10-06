<?php

namespace App\Enums;

enum TelegramOutboundEffectType: string
{
    case ManualCheckoutInstructions = 'checkout.manual_payment_instructions';
    case KlikQrisCheckoutInstructions = 'checkout.klikqris_payment_instructions';
    case SakurupiahCheckoutInstructions = 'checkout.sakurupiah_payment_instructions';
    case PakasirCheckoutInstructions = 'checkout.pakasir_payment_instructions';
    case BinanceCheckoutInstructions = 'checkout.binance_payment_instructions';
    case BscCheckoutInstructions = 'checkout.bsc_payment_instructions';
    case BalanceTopUpInstructions = 'balance.top_up_instructions';
    case BalanceTopUpReceipt = 'balance.top_up_receipt';
    case BalanceTopUpPaymentProof = 'admin.balance_top_up_payment_proof';
    case BalanceTopUpOwnerAlert = 'admin.balance_top_up_owner_alert';
    case BalanceTopUpChannelProof = 'balance.top_up_channel_proof';
    case OwnerCompensationNotification = 'balance.owner_compensation_notification';
    case ReceiptCredentials = 'receipt.credentials';
    case AdminOrderPendingNotification = 'admin.order_pending_notification';
    case AdminOrderPaidNotification = 'admin.order_paid_notification';
    case AdminOrderExpiredNotification = 'admin.order_expired_notification';
    case AdminBroadcastRecipient = 'admin.broadcast_recipient';
    case ManualPaymentProof = 'admin.manual_payment_proof';
    case SalesProofPost = 'sales_proof.post';
    case StockNotificationRecipient = 'stock.notification_recipient';
    case PriceChangeNotificationRecipient = 'price_change.notification_recipient';
    case PriceChangeStoreChannel = 'price_change.store_channel';
    case ReceiptTxtDownload = 'receipt.txt_download';
    case ReceiptOrderActions = 'receipt.order_actions';
    case WarrantyNotification = 'order.warranty_notification';
    case WarrantyDeadlineReminder = 'order.warranty_deadline_reminder';
    case BalanceAdjustmentNotice = 'balance.adjustment_notice';
    case SupplierHealth = 'admin.supplier_health';
    case UsdtTopUpReceipt = 'usdt.top_up_receipt';
    case BalanceOperationsAlert = 'admin.balance_operations_alert';
    case UsdtRefundReceipt = 'usdt.refund_receipt';
}
