<?php

namespace App\Services\StoreBalance\TopUp;

use App\Models\ManualPaymentSetting;
use App\Support\PaymentProvider;

/**
 * Resolve the payment provider for a new Store Balance top-up.
 *
 * The selection is snapshotted on the top-up at confirmation time. Later
 * lifecycle decisions read the top-up's own provider, so a provider switch
 * never rewrites, re-targets, or invalidates an existing top-up.
 */
final readonly class StoreBalanceTopUpProviderResolver
{
    public function resolve(?ManualPaymentSetting $setting = null): StoreBalanceTopUpProviderSelection
    {
        $setting ??= ManualPaymentSetting::current();
        $configured = $setting?->paymentProvider() ?? PaymentProvider::MANUAL;

        return new StoreBalanceTopUpProviderSelection(
            provider: in_array($configured, PaymentProvider::allowed(), true)
                ? $configured
                : PaymentProvider::MANUAL,
            settingId: $setting?->getKey(),
            providerVersion: (int) ($setting?->payment_provider_version ?? 0),
        );
    }
}
