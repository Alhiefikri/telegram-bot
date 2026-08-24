<?php

namespace Database\Factories;

use App\Models\BscPaymentTransferEvidence;
use App\Models\PaymentBscDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BscPaymentTransferEvidence> */
class BscPaymentTransferEvidenceFactory extends Factory
{
    protected $model = BscPaymentTransferEvidence::class;

    public function definition(): array
    {
        $hash = '0x'.$this->faker->unique()->regexify('[a-f0-9]{64}');
        $contract = strtolower(trim((string) config('services.bsc.usdt_contract')));
        if (preg_match('/^0x[a-f0-9]{40}$/', $contract) !== 1) {
            $contract = '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}');
        }

        return [
            'payment_id' => PaymentBscDetail::factory(),
            'tx_hash' => $hash,
            'token_contract' => $contract,
            'receiver_address' => '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}'),
            'from_address' => '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}'),
            'amount_units' => '1000000000000000000',
            'block_number' => 100,
            'block_hash' => '0x'.$this->faker->unique()->regexify('[a-f0-9]{64}'),
            'block_timestamp' => now()->subMinute(),
            'receipt_status' => 'success',
            'log_index' => 0,
            'confirmations' => 3,
            'verification_status' => PaymentBscDetail::STATUS_CONFIRMING,
            'evidence_payload' => null,
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ];
    }
}
