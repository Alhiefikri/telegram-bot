<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Services\Checkout\PaymentPlan\PakasirCheckoutPaymentSnapshot;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Support\Collection;

final readonly class PakasirCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const HOSTED_IDENTITY = PaymentProvider::PAKASIR.'|HOSTED';

    public const API_QRIS_IDENTITY = PaymentProvider::PAKASIR.'|API_QRIS';

    public const PAYMENT_CURRENCY = 'IDR';

    public function __construct(
        private PakasirProviderReadiness $readiness,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $flow = $context->preflight->method;
        if (! PakasirCheckoutFlow::isAllowed($flow)) {
            throw new InvalidCheckout(PakasirCheckoutFlow::validationMessage());
        }

        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::PAKASIR,
            requestedMethod: $flow,
        ));

        $readiness = $this->readiness->checkout($flow);
        if (! $readiness->isReady()) {
            throw new InvalidCheckout(trim('Pakasir payment rail is not ready. '.$readiness->configurationMessage()));
        }

        $expiresAt = $context->requestedExpiresAt
            ?? now()->addMinutes(
                $context->lockedSetting?->qrisPaymentTtlMinutes()
                    ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES,
            );
        $amount = $this->baseAmount($context->items, $context->products);
        $snapshot = new PakasirCheckoutPaymentSnapshot(
            provider: PaymentProvider::PAKASIR,
            method: $flow,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            expiresAt: $expiresAt,
            flow: $flow,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::PAKASIR,
            method: $flow,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $amount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::PakasirInstructions,
        );
    }

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    private function baseAmount(Collection $items, Collection $products): int
    {
        return $items->sum(fn ($item): int => $products[$item->product_id]->price_amount * $item->quantity);
    }
}
