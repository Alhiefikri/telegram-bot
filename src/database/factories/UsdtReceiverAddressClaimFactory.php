<?php

namespace Database\Factories;

use App\Enums\UsdtReceiverChannel;
use App\Models\UsdtReceiverAddressClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsdtReceiverAddressClaim> */
class UsdtReceiverAddressClaimFactory extends Factory
{
    protected $model = UsdtReceiverAddressClaim::class;

    public function definition(): array
    {
        return [
            'chain_id' => 56,
            'address_normalized' => '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}'),
            'channel' => UsdtReceiverChannel::Checkout,
            'claimed_at' => now(),
        ];
    }

    public function channel(UsdtReceiverChannel $channel): static
    {
        return $this->state(['channel' => $channel]);
    }
}
