<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Models\Payment;
use Carbon\CarbonImmutable;

interface CheckoutPaymentRailSnapshot
{
    public function provider(): string;

    public function method(): string;

    public function paymentCurrency(): string;

    public function amount(): int;

    public function expiresAt(): CarbonImmutable;

    /**
     * Persist only this rail's typed extension fields.
     *
     * Common Payment fields are written by CheckoutPaymentPlanWriter.
     */
    public function persist(Payment $payment): void;
}
