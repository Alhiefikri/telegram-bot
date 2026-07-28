<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;

class KlikQrisPaymentEventLogger
{
    public function logProviderCreate(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent
    {
        $receivedAt = now();

        return PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => 'klikqris',
            'event_type' => PaymentEventType::ProviderCreate,
            'merchant_ref' => $transaction->orderId(),
            'provider_status' => $transaction->status(),
            'raw_payload' => $transaction->sanitizedPayload(),
            'received_at' => $receivedAt,
            'last_received_at' => $receivedAt,
            'received_count' => 1,
        ]);
    }
}
