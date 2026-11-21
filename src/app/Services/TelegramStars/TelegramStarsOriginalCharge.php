<?php

namespace App\Services\TelegramStars;

use App\Enums\ExternalMoneyReceiptTargetType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Services\Telegram\TelegramStarsUpdate;
use Illuminate\Support\Facades\DB;

final readonly class TelegramStarsOriginalCharge
{
    private function __construct(
        public int $receiptId,
        public string $namespace,
        public string $chargeId,
        public int $buyer,
        public string $payload,
        public int $total,
    ) {}

    public static function forPayment(Order $order, Payment $payment): ?self
    {
        $target = PaymentTelegramStarsDetail::where('payment_id', $payment->id)->first();
        if ($target === null || ! TelegramStarsInvoiceTarget::matches($target, $order, $payment)) {
            return null;
        }
        $source = DB::table('telegram_stars_settlements as settlement')
            ->join('external_money_receipts as receipt', 'receipt.id', '=', 'settlement.external_money_receipt_id')
            ->join('telegram_updates as inbox', 'inbox.id', '=', 'settlement.telegram_update_id')
            ->where('settlement.payment_id', $payment->id)->where('receipt.provider', 'telegram_stars')
            ->where('receipt.currency', 'XTR')->where('receipt.decimals', 0)->where('receipt.amount_units', (string) $target->total_xtr)
            ->where('receipt.target_type', ExternalMoneyReceiptTargetType::OrderPayment->value)->where('receipt.target_id', (string) $order->id)
            ->where('inbox.stars_event', TelegramStarsUpdate::SUCCESS)->whereNotNull('inbox.processed_at')
            ->whereColumn('inbox.stars_namespace', 'receipt.account_namespace')
            ->first(['receipt.id', 'receipt.canonical_key', 'inbox.stars_namespace', 'inbox.stars_charge_hash', 'inbox.payload']);
        if ($source === null || $source->stars_namespace === null) {
            return null;
        }
        $message = json_decode($source->payload, true)['message'] ?? [];
        $event = $message[TelegramStarsUpdate::SUCCESS] ?? [];
        $charge = $event['telegram_payment_charge_id'] ?? null;
        if (! is_string($charge) || trim($charge) === '' || strlen($charge) > 256 || str_contains($charge, "\0")
            || $source->stars_charge_hash !== TelegramStarsChargeIdentity::hash($charge)
            || $source->canonical_key !== TelegramStarsChargeIdentity::incoming($source->stars_namespace, $source->stars_charge_hash)->canonicalKey()
            || ($message['from']['id'] ?? null) !== $target->telegram_user_id
            || ($message['chat']['type'] ?? null) !== 'private' || ($message['chat']['id'] ?? null) !== $target->chat_id
            || ($event['invoice_payload'] ?? null) !== $target->invoice_payload
            || ($event['currency'] ?? null) !== 'XTR' || ($event['total_amount'] ?? null) !== $target->total_xtr) {
            return null;
        }

        return new self((int) $source->id, $source->stars_namespace, $charge, $target->telegram_user_id, $target->invoice_payload, $target->total_xtr);
    }
}
