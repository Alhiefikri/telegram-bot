<?php

namespace Database\Factories;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtDepositReceiverSource;
use App\Enums\UsdtReceiverChannel;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\UsdtReceiverAddressClaim;
use App\Services\Bsc\BscReadiness;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/** @extends Factory<CustomerUsdtDepositReceiver> */
class CustomerUsdtDepositReceiverFactory extends Factory
{
    protected $model = CustomerUsdtDepositReceiver::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'chain_id' => UsdtDepositReceiverRegistry::SUPPORTED_CHAIN_ID,
            'address' => '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}'),
            'address_normalized' => null,
            'token_contract' => BscReadiness::APPROVED_USDT_CONTRACT,
            'token_decimals' => 18,
            'custody_namespace' => UsdtDepositReceiverRegistry::OPERATOR_CUSTODY_NAMESPACE,
            'source' => UsdtDepositReceiverSource::Owner,
            'verification_status' => BscReceiverVerificationStatus::PendingVerification,
            'verification_method' => null,
            'verified_at' => null,
            'verified_by' => null,
            'registered_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CustomerUsdtDepositReceiver $receiver): void {
            $address = CustomerUsdtDepositReceiver::normalizeAddress(
                (string) ($receiver->address ?: $receiver->address_normalized),
            );

            $claim = UsdtReceiverAddressClaim::query()->firstOrCreate(
                ['chain_id' => (int) $receiver->chain_id, 'address_normalized' => $address],
                ['channel' => UsdtReceiverChannel::CustomerDeposit, 'claimed_at' => now()],
            );

            if ($claim->channel !== UsdtReceiverChannel::CustomerDeposit) {
                throw new RuntimeException('This factory address is already claimed by the checkout receiver channel.');
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
            'verification_method' => 'eip191_eoa',
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

    public function provisioned(string $custodyNamespace = 'fake_custody'): static
    {
        return $this->state(fn (): array => [
            'source' => UsdtDepositReceiverSource::Provisioner,
            'custody_namespace' => $custodyNamespace,
            'verification_status' => BscReceiverVerificationStatus::ManualVerified,
            'verification_method' => 'custody_provisioner:'.$custodyNamespace,
            'verified_at' => now(),
        ]);
    }

    public function retired(?string $reason = 'rotasi address'): static
    {
        // Retirement columns are intentionally not mass assignable: the owner
        // service sets them explicitly, so the factory must fill them directly.
        return $this->afterMaking(function (CustomerUsdtDepositReceiver $receiver) use ($reason): void {
            $receiver->forceFill([
                'retired_at' => now(),
                'retirement_reason' => $reason,
            ]);
        });
    }
}
