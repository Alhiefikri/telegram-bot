<?php

namespace App\Services\Checkout;

interface CheckoutPaymentPreparation
{
    public function preflight(
        CheckoutPaymentPreflightContext $context,
    ): CheckoutPaymentPreflight;

    public function prepare(
        CheckoutPaymentPreparationContext $context,
    ): CheckoutPaymentPlan;
}
