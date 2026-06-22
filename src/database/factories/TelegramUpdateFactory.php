<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelegramUpdate> */
class TelegramUpdateFactory extends Factory
{
    protected $model = TelegramUpdate::class;

    public function definition(): array
    {
        return ['update_id' => fake()->unique()->numberBetween(100000, 999999999), 'customer_id' => Customer::factory(), 'payload' => ['message' => ['text' => '/start']], 'processed_at' => null, 'created_at' => now()];
    }
}
