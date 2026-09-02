<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;

final readonly class PaymentProviderResolver
{
    public function resolve(?string $paymentMethod = null, ?ManualPaymentSetting $setting = null): PaymentProviderSelection
    {
        $setting ??= ManualPaymentSetting::current();
        $paymentRail = CheckoutPaymentIntents::normalizePaymentRail($paymentMethod);
        if (! CheckoutPaymentIntents::isSupportedPaymentRail($paymentRail)) {
            throw new InvalidCheckout('Metode pembayaran tidak tersedia.');
        }
        $requestedProvider = $setting?->paymentProvider() ?? PaymentProvider::MANUAL;

        $providerVersion = (int) ($setting?->payment_provider_version ?? 0);
        $settingId = $setting?->getKey();

        // Binance rails are customer-selected payment rails, not values of the
        // active QRIS provider switch. Resolve them directly and do not let a
        // provider readiness result rewrite the rail.
        if (CheckoutPaymentIntents::isBinancePaymentRail($paymentRail)) {
            return new PaymentProviderSelection(
                requestedProvider: $requestedProvider,
                provider: PaymentProvider::MANUAL,
                providerVersion: $providerVersion,
                settingId: $settingId,
                paymentIntent: $this->intentFor($paymentRail, PaymentProvider::MANUAL),
            );
        }
        if ($requestedProvider === PaymentProvider::PAKASIR) {
            throw new InvalidCheckout('Pakasir sudah dipensiunkan untuk checkout baru.');
        }

        $provider = $requestedProvider;

        $intent = $this->intentFor($paymentRail, $provider);

        return new PaymentProviderSelection(
            requestedProvider: $requestedProvider,
            provider: $provider,
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

    private function intentFor(?string $paymentMethod, string $provider): CheckoutPaymentIntent
    {
        $paymentRail = CheckoutPaymentIntents::normalizePaymentRail($paymentMethod);

        if (CheckoutPaymentIntents::isBinancePayIdRail($paymentRail)) {
            return CheckoutPaymentIntents::binancePayId();
        }
        if (CheckoutPaymentIntents::isUsdtBep20Rail($paymentRail)) {
            return CheckoutPaymentIntents::usdtBep20();
        }

        return match ($provider) {
            PaymentProvider::KLIKQRIS => CheckoutPaymentIntents::klikQris(),
            PaymentProvider::SAKURUPIAH => CheckoutPaymentIntents::sakurupiah(),
            default => CheckoutPaymentIntents::manualQris(),
        };
    }
}
