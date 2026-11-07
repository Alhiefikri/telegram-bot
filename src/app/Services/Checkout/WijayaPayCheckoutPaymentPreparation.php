<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutPaymentAmountOutsideLimits;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Services\Checkout\PaymentPlan\WijayaPayCheckoutPaymentSnapshot;
use App\Services\WijayaPay\WijayaPayCreateInvoice;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Services\WijayaPay\WijayaPayProviderReadinessReport;
use App\Support\PaymentProvider;

final readonly class WijayaPayCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS;

    public const PAYMENT_CURRENCY = 'IDR';

    public const IDENTITY = PaymentProvider::WIJAYAPAY.'|'.self::METHOD;

    public function __construct(
        private WijayaPayProviderReadiness $readiness,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::withState(
            $context,
            $this->readiness->observe(),
        );
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::WIJAYAPAY,
            requestedMethod: self::METHOD,
        ));

        $report = $context->preflight->stateAs(WijayaPayProviderReadinessReport::class);
        $this->readiness->assertCheckoutSnapshot($report, $context->lockedSetting);

        $channel = $report->qrisChannel();
        if ($channel === null || ! $channel->isQris() || ! $channel->isActive()) {
            throw new CheckoutPaymentUnavailable('WijayaPay QRIS checkout is not ready. Try again later or contact support.');
        }

        $amount = $context->pricing->idrTotal();
        if ($amount < 1
            || ($channel->minimumAmount() > 0 && $amount < $channel->minimumAmount())
            || ($channel->maximumAmount() > 0 && $amount > $channel->maximumAmount())
        ) {
            throw new CheckoutPaymentAmountOutsideLimits(
                $channel->minimumAmount(),
                $channel->maximumAmount(),
                'WijayaPay',
            );
        }

        $expiresAt = now()->addMinutes(15);
        $snapshot = new WijayaPayCheckoutPaymentSnapshot(
            provider: PaymentProvider::WIJAYAPAY,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::WIJAYAPAY,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::WijayaPayInstructions,
        );
    }
}
