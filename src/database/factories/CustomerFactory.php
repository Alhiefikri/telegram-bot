<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return ['telegram_id' => fake()->unique()->numberBetween(100000, 999999999), 'first_name' => fake()->firstName(), 'username' => fake()->userName(), 'phone_number' => fake()->optional()->phoneNumber()];
    }
}
