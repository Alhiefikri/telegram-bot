<?php

namespace Database\Factories;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtReceiverChannel;
use App\Models\BscPaymentReceiver;
use App\Models\UsdtReceiverAddressClaim;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/** @extends Factory<BscPaymentReceiver> */
class BscPaymentReceiverFactory extends Factory
{
    protected $model = BscPaymentReceiver::class;

    public function definition(): array
    {
        $address = '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}');
        $tokenContract = strtolower(trim((string) config('services.bsc.usdt_contract')));
        if (preg_match('/^0x[a-f0-9]{40}$/', $tokenContract) !== 1) {
            $tokenContract = '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}');
        }

        return [
            'label' => 'BSC receiver '.$this->faker->unique()->numerify('###'),
            'address' => $address,
            'address_normalized' => $address,
            'chain_id' => 56,
            'token_contract' => $tokenContract,
            'token_decimals' => 18,
            'is_active' => false,
            'verification_status' => BscReceiverVerificationStatus::PendingVerification,
            'verification_method' => null,
            'verified_at' => null,
            'verified_by' => null,
            'last_assigned_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BscPaymentReceiver $receiver): void {
            $address = BscPaymentReceiver::normalizeAddress((string) ($receiver->address ?: $receiver->address_normalized));

            $claim = UsdtReceiverAddressClaim::query()->firstOrCreate(
                ['chain_id' => (int) ($receiver->chain_id ?: 56), 'address_normalized' => $address],
                ['channel' => UsdtReceiverChannel::Checkout, 'claimed_at' => now()],
            );

            if ($claim->channel !== UsdtReceiverChannel::Checkout) {
                throw new RuntimeException('This factory address is already claimed by the Customer USDT deposit channel.');
            }

            $receiver->address = $address;
            $receiver->address_normalized = $address;
            $receiver->address_claim_id = $claim->getKey();
        });
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verification_status' => BscReceiverVerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function manuallyVerified(): static
    {
        return $this->state(fn (): array => [
            'verification_status' => BscReceiverVerificationStatus::ManualVerified,
            'verification_method' => 'manual_verified',
            'verified_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
