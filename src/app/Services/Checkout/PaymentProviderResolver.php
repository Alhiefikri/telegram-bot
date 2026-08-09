<?php

namespace App\Services\Checkout;

use App\Models\ManualPaymentSetting;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;

final readonly class PaymentProviderResolver
{
    public function __construct(
        private PakasirProviderReadiness $pakasirReadiness,
    ) {}

    public function resolve(?string $paymentMethod = null, ?ManualPaymentSetting $setting = null): PaymentProviderSelection
    {
        $setting ??= ManualPaymentSetting::current();
        $requestedProvider = $setting?->paymentProvider() ?? PaymentProvider::MANUAL;
        $configuredPakasirCheckoutFlow = (string) ($setting?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
        $pakasirCheckoutFlow = PakasirCheckoutFlow::isAllowed($configuredPakasirCheckoutFlow)
            ? $configuredPakasirCheckoutFlow
            : PakasirCheckoutFlow::HOSTED;
        $provider = $requestedProvider;

        if ($requestedProvider === PaymentProvider::PAKASIR) {
            $readiness = $this->pakasirReadiness->checkout($configuredPakasirCheckoutFlow);
            $pakasirCheckoutFlow = $readiness->checkoutFlow();
            if (! $readiness->isReady()) {
                $provider = PaymentProvider::MANUAL;
            }
        }

        $intent = $this->intentFor($paymentMethod, $provider, $pakasirCheckoutFlow);
        if ($this->isBinancePayment($paymentMethod)) {
            return new PaymentProviderSelection(
                requestedProvider: $requestedProvider,
                provider: PaymentProvider::MANUAL,
                pakasirCheckoutFlow: $pakasirCheckoutFlow,
                providerVersion: (int) ($setting?->payment_provider_version ?? 0),
                settingId: $setting?->getKey(),
                paymentIntent: $intent,
            );
        }

        return new PaymentProviderSelection(
            requestedProvider: $requestedProvider,
            provider: $provider,
            pakasirCheckoutFlow: $pakasirCheckoutFlow,
            providerVersion: (int) ($setting?->payment_provider_version ?? 0),
            settingId: $setting?->getKey(),
            paymentIntent: new ProviderBoundCheckoutPaymentIntent(
                intent: $intent,
                configuredProvider: $requestedProvider,
                providerVersion: (int) ($setting?->payment_provider_version ?? 0),
                settingId: $setting?->getKey(),
            ),
        );
    }

    public function matchesCurrentSetting(
        ProviderBoundCheckoutPaymentIntent $intent,
        ?ManualPaymentSetting $setting,
    ): bool {
        return $intent->settingId === $setting?->getKey()
            && $intent->providerVersion === (int) ($setting?->payment_provider_version ?? 0)
            && $intent->configuredProvider === ($setting?->paymentProvider() ?? PaymentProvider::MANUAL);
    }

    private function intentFor(?string $paymentMethod, string $provider, string $pakasirCheckoutFlow): CheckoutPaymentIntent
    {
        if ($this->isBinancePayment($paymentMethod)) {
            return CheckoutPaymentIntents::binancePay();
        }

        return match ($provider) {
            PaymentProvider::PAKASIR => CheckoutPaymentIntents::pakasir($pakasirCheckoutFlow),
            PaymentProvider::KLIKQRIS => CheckoutPaymentIntents::klikQris(),
            default => CheckoutPaymentIntents::manualQris(),
        };
    }

    private function isBinancePayment(?string $paymentMethod): bool
    {
        return in_array(strtolower(trim((string) $paymentMethod)), ['binance_pay', 'binance_pay_id'], true);
    }
}
