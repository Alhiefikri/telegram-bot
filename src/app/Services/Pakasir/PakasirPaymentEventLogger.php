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
            'raw_payload' => $this->sanitize($rawPayload),
            'received_at' => now(),
        ]);
    }

    public function logProviderCreate(Payment $payment, PakasirQrisTransaction $transaction): PaymentEvent
    {
        return PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => 'pakasir',
            'event_type' => PaymentEventType::ProviderCreate,
            'merchant_ref' => $transaction->orderId(),
            'provider_status' => null,
            'raw_payload' => $transaction->sanitizedPayload(),
            'received_at' => now(),
        ]);
    }

    private function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        return in_array($normalized, ['paymentnumber', 'apikey', 'secret', 'token', 'password', 'authorization'], true);
    }
}
