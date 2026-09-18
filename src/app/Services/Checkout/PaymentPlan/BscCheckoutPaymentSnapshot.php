<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Services\Bsc\BscPaymentReceiverSnapshot;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class BscCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public readonly int $receiverId;

    public readonly string $receiverAddress;

    public readonly int $chainId;

    public readonly string $tokenContract;

    public readonly int $tokenDecimals;

    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
        public BscPaymentReceiverSnapshot $receiver,
        public int $baseAmountMicro,
        public int $payableAmountMicro,
        public string $expectedTokenUnits,
        public string $verificationStatus,
        public int $requiredConfirmations = ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS,
        public bool $requiresFinalizedBlock = false,
        public int $confirmationGraceMinutes = ManualPaymentSetting::DEFAULT_BSC_CONFIRMATION_GRACE_MINUTES,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        $this->receiverId = $receiver->receiverId;
        $this->receiverAddress = $receiver->address;
        $this->chainId = $receiver->chainId;
        $this->tokenContract = $receiver->tokenContract;
        $this->tokenDecimals = $receiver->tokenDecimals;

        if ($provider !== PaymentProvider::MANUAL
            || $method !== 'USDT_BEP20'
            || $paymentCurrency !== 'USDT') {
            throw new InvalidArgumentException('BSC payment snapshot has an invalid rail identity.');
        }

        if ($this->receiverId < 1 || $this->chainId !== 56 || $this->tokenDecimals < 6) {
            throw new InvalidArgumentException('BSC receiver snapshot is incomplete.');
        }

        if (preg_match('/^0x[a-f0-9]{40}$/', $this->receiverAddress) !== 1
            || preg_match('/^0x[a-f0-9]{40}$/', $this->tokenContract) !== 1) {
            throw new InvalidArgumentException('BSC receiver snapshot contains an invalid address.');
        }

        if ($this->baseAmountMicro < 0
            || $this->payableAmountMicro < 0
            || $this->baseAmountMicro !== $this->payableAmountMicro
            || $this->amount !== $this->payableAmountMicro) {
            throw new InvalidArgumentException('BSC payment amounts are inconsistent.');
        }

        if (preg_match('/^\d+$/', $this->expectedTokenUnits) !== 1 || $this->expectedTokenUnits === '') {
            throw new InvalidArgumentException('BSC expected token units are required.');
        }

        $expected = $this->payableAmountMicro === 0
            ? '0'
            : (string) $this->payableAmountMicro.str_repeat('0', $this->tokenDecimals - 6);
        if ($this->expectedTokenUnits !== $expected) {
            throw new InvalidArgumentException('BSC expected token units do not match the payable amount.');
        }

        if ($this->requiredConfirmations < ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS
            || $this->requiredConfirmations > 100) {
            throw new InvalidArgumentException('BSC required confirmations are outside the supported range.');
        }

        if ($this->confirmationGraceMinutes < 0 || $this->confirmationGraceMinutes > 60) {
            throw new InvalidArgumentException('BSC confirmation grace is outside the supported range.');
        }

        if (trim($this->verificationStatus) === '') {
            throw new InvalidArgumentException('BSC verification status is required.');
        }
    }

    public function persist(Payment $payment): void
    {
        PaymentBscDetail::query()->create([
            'payment_id' => $payment->getKey(),
            'receiver_id' => $this->receiverId,
            'receiver_address' => $this->receiverAddress,
            'chain_id' => $this->chainId,
            'token_contract' => $this->tokenContract,
            'token_decimals' => $this->tokenDecimals,
            'required_confirmations' => $this->requiredConfirmations,
            'requires_finalized_block' => $this->requiresFinalizedBlock,
            'confirmation_grace_minutes' => $this->confirmationGraceMinutes,
            'base_amount_micro' => $this->baseAmountMicro,
            'payable_amount_micro' => $this->payableAmountMicro,
            'expected_token_units' => $this->expectedTokenUnits,
            'verification_status' => $this->verificationStatus,
        ]);
    }
}
