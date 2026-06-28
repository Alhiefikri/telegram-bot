<?php

namespace App\Services\Pakasir;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;

class PakasirPaymentEventLogger
{
    public function log(PaymentEventType $type, array $rawPayload, ?Payment $payment, ?PakasirTransaction $transaction = null): PaymentEvent
    {
        $transaction ??= PakasirTransaction::fromPayload($rawPayload);

        return PaymentEvent::create([
            'payment_id' => $payment?->id,
            'provider' => 'pakasir',
            'event_type' => $type,
            'merchant_ref' => $transaction?->orderId(),
            'provider_status' => $transaction?->status(),
            'raw_payload' => $rawPayload,
            'received_at' => now(),
        ]);
    }
}
