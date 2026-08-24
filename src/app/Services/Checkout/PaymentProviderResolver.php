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
        $paymentRail = CheckoutPaymentIntents::normalizePaymentRail($paymentMethod);
        $requestedProvider = $setting?->paymentProvider() ?? PaymentProvider::MANUAL;
        $configuredPakasirCheckoutFlow = (string) ($setting?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
        $pakasirCheckoutFlow = PakasirCheckoutFlow::isAllowed($configuredPakasirCheckoutFlow)
            ? $configuredPakasirCheckoutFlow
            : PakasirCheckoutFlow::HOSTED;

        $providerVersion = (int) ($setting?->payment_provider_version ?? 0);
        $settingId = $setting?->getKey();

        // Binance rails are customer-selected payment rails, not values of the
        // active QRIS provider switch. Resolve them directly and do not let a
        // Pakasir/KlikQRIS switch or readiness result rewrite the rail.
        if (CheckoutPaymentIntents::isBinancePaymentRail($paymentRail)) {
            return new PaymentProviderSelection(
                requestedProvider: $requestedProvider,
                provider: PaymentProvider::MANUAL,
                pakasirCheckoutFlow: $pakasirCheckoutFlow,
                providerVersion: $providerVersion,
                settingId: $settingId,
                paymentIntent: $this->intentFor($paymentRail, PaymentProvider::MANUAL, $pakasirCheckoutFlow),
            );
        }

        $provider = $requestedProvider;

        if ($requestedProvider === PaymentProvider::PAKASIR) {
            $readiness = $this->pakasirReadiness->checkout($configuredPakasirCheckoutFlow);
            $pakasirCheckoutFlow = $readiness->checkoutFlow();
            if (! $readiness->isReady()) {
                $provider = PaymentProvider::MANUAL;
            }
        }

        $intent = $this->intentFor($paymentRail, $provider, $pakasirCheckoutFlow);

        return new PaymentProviderSelection(
            requestedProvider: $requestedProvider,
            provider: $provider,
            pakasirCheckoutFlow: $pakasirCheckoutFlow,
            providerVersion: $providerVersion,
            settingId: $settingId,
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
        $paymentRail = CheckoutPaymentIntents::normalizePaymentRail($paymentMethod);

        if (CheckoutPaymentIntents::isBinancePayIdRail($paymentRail)) {
            return CheckoutPaymentIntents::binancePayId();
        }
        if (CheckoutPaymentIntents::isUsdtBep20Rail($paymentRail)) {
            return CheckoutPaymentIntents::usdtBep20();
        }

        return match ($provider) {
            PaymentProvider::PAKASIR => CheckoutPaymentIntents::pakasir($pakasirCheckoutFlow),
            PaymentProvider::KLIKQRIS => CheckoutPaymentIntents::klikQris(),
            default => CheckoutPaymentIntents::manualQris(),
        };
    }
}
