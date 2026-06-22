<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'provider' => 'tripay', 'merchant_ref' => 'MR-'.fake()->unique()->numerify('########'), 'provider_reference' => fake()->unique()->numerify('TRX########'), 'method' => 'QRIS', 'amount' => 10000, 'currency' => 'IDR', 'status' => PaymentStatus::Pending, 'paid_at' => null, 'expired_at' => now()->addHour()];
    }
}
