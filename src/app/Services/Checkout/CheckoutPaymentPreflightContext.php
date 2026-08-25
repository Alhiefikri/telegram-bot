<?php

namespace App\Services\Checkout;

use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;

final readonly class CheckoutPaymentPreflightContext
{
    public function __construct(
        public ?ManualPaymentSetting $currentSetting,
        public string $requestedProvider,
        public string $requestedMethod,
    ) {}

    public function identity(): string
    {
        return CheckoutPaymentPreparationIdentity::key(
            $this->requestedProvider,
            $this->requestedMethod,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'identity' => $this->identity(),
            'setting_id' => $this->currentSetting?->getKey(),
            'setting_version' => (int) ($this->currentSetting?->payment_provider_version ?? 0),
            'setting_updated_at' => $this->currentSetting?->updated_at?->toISOString(),
            'setting_provider' => PaymentProvider::normalize($this->currentSetting?->paymentProvider()),
        ], JSON_THROW_ON_ERROR));
    }
}
