<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Models\Payment;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class BinancePayCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
        public string $verificationMode,
        public bool $autoVerificationEnabled,
        public string $receiverType,
        public string $receiverValue,
        public string $verificationStatus,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        if ($provider !== PaymentProvider::MANUAL
            || $method !== 'BINANCE_PAY_ID'
            || $paymentCurrency !== 'USDT') {
            throw new InvalidArgumentException('Binance Pay payment snapshot has an invalid rail identity.');
        }

        if (! in_array($verificationMode, ['amount_unique', 'order_id'], true)) {
            throw new InvalidArgumentException('Binance verification mode is invalid.');
        }

        if (! in_array($receiverType, ['pay_id', 'uid'], true) || trim($receiverValue) === '') {
            throw new InvalidArgumentException('Binance receiver snapshot is incomplete.');
        }

        if (trim($verificationStatus) === '') {
            throw new InvalidArgumentException('Binance verification status is required.');
        }
    }

    public function mode(): string
    {
        return $this->verificationMode;
    }

    public function persist(Payment $payment): void
    {
        $payment->forceFill([
            'binance_auto_verification_enabled' => $this->autoVerificationEnabled,
            'binance_verification_mode' => $this->verificationMode,
            'binance_receiver_type' => $this->receiverType,
            'binance_receiver_value' => $this->receiverValue,
            'binance_verification_status' => $this->verificationStatus,
        ])->save();
    }
}
