<?php

namespace Database\Factories;

use App\Models\BscPaymentReceiver;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Services\Bsc\BscUniquePaymentAmount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentBscDetail> */
class PaymentBscDetailFactory extends Factory
{
    protected $model = PaymentBscDetail::class;

    public function configure(): static
    {
        return $this->afterCreating(function (PaymentBscDetail $detail): void {
            $payment = $detail->payment()->firstOrFail();
            $receiver = $detail->receiver()->firstOrFail();
            $amount = (int) $payment->amount;

            $detail->forceFill([
                'receiver_address' => $receiver->address_normalized,
                'chain_id' => $receiver->chain_id,
                'token_contract' => $receiver->token_contract,
                'token_decimals' => $receiver->token_decimals,
                'base_amount_micro' => $amount,
                'payable_amount_micro' => $amount,
                'expected_token_units' => app(BscUniquePaymentAmount::class)->expectedTokenUnits($amount, (int) $receiver->token_decimals),
            ])->save();
        });
    }

    public function definition(): array
    {
        $address = '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}');
        $tokenContract = strtolower(trim((string) config('services.bsc.usdt_contract')));
        if (preg_match('/^0x[a-f0-9]{40}$/', $tokenContract) !== 1) {
            $tokenContract = '0x'.$this->faker->unique()->regexify('[a-f0-9]{40}');
        }

        return [
            'payment_id' => Payment::factory()->state([
                'provider' => 'manual',
                'method' => 'USDT_BEP20',
                'amount' => 1_000_000,
                'currency' => 'USDT',
            ]),
            'receiver_id' => BscPaymentReceiver::factory()->verified()->active(),
            'receiver_address' => $address,
            'chain_id' => 56,
            'token_contract' => $tokenContract,
            'token_decimals' => 18,
            'required_confirmations' => 3,
            'requires_finalized_block' => false,
            'confirmation_grace_minutes' => 3,
            'base_amount_micro' => 1_000_000,
            'payable_amount_micro' => 1_000_000,
            'expected_token_units' => app(BscUniquePaymentAmount::class)->expectedTokenUnits(1_000_000, 18),
            'verification_status' => PaymentBscDetail::STATUS_AWAITING_TRANSFER,
            'verification_grace_until' => null,
            'last_verified_at' => null,
            'current_tx_hash' => null,
            'current_confirmations' => null,
            'current_block_number' => null,
            'current_from_address' => null,
            'evidence_payload' => null,
        ];
    }

    public function forPayment(Payment $payment): static
    {
        return $this->state([
            'payment_id' => $payment->getKey(),
            'base_amount_micro' => $payment->amount,
            'payable_amount_micro' => $payment->amount,
            'expected_token_units' => app(BscUniquePaymentAmount::class)->expectedTokenUnits((int) $payment->amount, 18),
        ]);
    }
}
