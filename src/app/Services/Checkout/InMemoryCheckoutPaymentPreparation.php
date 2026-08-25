<?php

namespace App\Services\Checkout;

/**
 * Small deterministic adapter for contract tests and migration seams.
 * It has no database, provider, RPC, queue, or Telegram dependencies.
 */
final readonly class InMemoryCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public function __construct(
        private CheckoutPaymentPlan $plan,
        private ?CheckoutPaymentPreflight $preflight = null,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        $preflight = $this->preflight ?? CheckoutPaymentPreflight::none($context);
        $preflight->assertMatches($context);

        return $preflight;
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: $this->plan->provider,
            requestedMethod: $this->plan->method,
        ));

        return $this->plan;
    }
}
