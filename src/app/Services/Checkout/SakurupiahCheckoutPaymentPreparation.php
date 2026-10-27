<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Services\Checkout\PaymentPlan\SakurupiahCheckoutPaymentSnapshot;
use App\Services\Sakurupiah\SakurupiahChannelPolicy;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahProviderReadinessReport;
use App\Support\PaymentProvider;

final readonly class SakurupiahCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS;

    public const PAYMENT_CURRENCY = 'IDR';

    public const IDENTITY = PaymentProvider::SAKURUPIAH.'|'.self::METHOD;

    public function __construct(
        private SakurupiahProviderReadiness $readiness,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::withState(
            $context,
            $this->readiness->observe(channelOverride: SakurupiahChannelPolicy::selected($context->currentSetting)),
        );
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::SAKURUPIAH,
            requestedMethod: self::METHOD,
        ));

        $report = $context->preflight->stateAs(SakurupiahProviderReadinessReport::class);
        $this->readiness->assertCheckoutSnapshot($report, $context->lockedSetting);

        $channel = $report->qrisChannel();
        if ($channel === null || ! $channel->isQris() || ! $channel->isActive()) {
            throw new InvalidCheckout('Sakurupiah QRIS checkout is not ready. Try again later or contact support.');
        }

        $amount = $context->pricing->idrTotal();
        if ($amount < $channel->minimumAmount() || $amount > $channel->maximumAmount()) {
            throw new InvalidCheckout('Sakurupiah QRIS amount is outside the provider limits. Try again later or contact support.');
        }

        $expiresAt = now()->addMinutes(15);
        $snapshot = new SakurupiahCheckoutPaymentSnapshot(
            provider: PaymentProvider::SAKURUPIAH,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
            channel: $channel->code,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::SAKURUPIAH,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::SakurupiahInstructions,
        );
    }
}
