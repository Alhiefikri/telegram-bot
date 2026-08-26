<?php

namespace App\Services\Pakasir;

use App\Support\PakasirCheckoutFlow;

final class PakasirProviderReadiness
{
    public function checkout(?string $checkoutFlow = null): PakasirProviderReadinessReport
    {
        $invalid = $this->invalidBasePaymentConfiguration();
        $checkoutFlow ??= PakasirCheckoutFlow::HOSTED;

        if (! PakasirCheckoutFlow::isAllowed($checkoutFlow)) {
            $invalid['pakasir_checkout_flow'] = PakasirCheckoutFlow::validationMessage();
            $checkoutFlow = PakasirCheckoutFlow::HOSTED;
        }

        return new PakasirProviderReadinessReport(
            checkoutFlow: $checkoutFlow,
            missing: $this->missingBasePaymentConfiguration(),
            invalid: $invalid,
        );
    }

    public function verification(?string $checkoutFlow = null): PakasirProviderReadinessReport
    {
        return new PakasirProviderReadinessReport(
            checkoutFlow: $this->configuredCheckoutFlowOrHosted($checkoutFlow),
            missing: $this->missingBasePaymentConfiguration(),
            invalid: $this->invalidBasePaymentConfiguration(),
        );
    }

    /**
     * @return list<string>
     */
    private function missingBasePaymentConfiguration(): array
    {
        $missing = [];

        if (blank(config('services.pakasir.slug'))) {
            $missing[] = 'PAKASIR_SLUG';
        }

        if (blank(config('services.pakasir.api_key'))) {
            $missing[] = 'PAKASIR_API_KEY';
        }

        if (blank(config('services.pakasir.base_url', 'https://app.pakasir.com'))) {
            $missing[] = 'PAKASIR_BASE_URL';
        }

        return $missing;
    }

    /**
     * @return array<string, string>
     */
    private function invalidBasePaymentConfiguration(): array
    {
        $invalid = [];
        $baseUrl = config('services.pakasir.base_url', 'https://app.pakasir.com');

        if (! blank($baseUrl) && ! is_string($baseUrl)) {
            $invalid['PAKASIR_BASE_URL'] = 'PAKASIR_BASE_URL must be a URL string.';
        }

        return $invalid;
    }

    private function configuredCheckoutFlowOrHosted(?string $checkoutFlow = null): string
    {
        $checkoutFlow ??= PakasirCheckoutFlow::HOSTED;

        return PakasirCheckoutFlow::isAllowed($checkoutFlow) ? $checkoutFlow : PakasirCheckoutFlow::HOSTED;
    }
}
