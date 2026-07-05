<?php

namespace Database\Factories;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentEvent> */
class PaymentEventFactory extends Factory
{
    protected $model = PaymentEvent::class;

    public function definition(): array
    {
        $receivedAt = now();

        return [
            'payment_id' => Payment::factory(),
            'provider' => 'pakasir',
            'event_type' => PaymentEventType::Callback,
            'merchant_ref' => 'MR-'.fake()->numerify('########'),
            'provider_reference' => null,
            'provider_status' => 'completed',
            'raw_payload' => ['status' => 'completed'],
            'signature' => null,
            'idempotency_key' => null,
            'received_count' => 1,
            'received_at' => $receivedAt,
            'last_received_at' => $receivedAt,
            'processed_at' => null,
        ];
    }
}
