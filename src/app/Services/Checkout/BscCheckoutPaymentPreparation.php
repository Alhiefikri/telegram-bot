<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Models\PaymentBscDetail;
use App\Services\Bsc\BscPaymentReceiverPool;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscReadinessReport;
use App\Services\Bsc\BscTokenAmount;
use App\Services\Checkout\PaymentPlan\BscCheckoutPaymentSnapshot;
use App\Support\PaymentProvider;
use Illuminate\Support\Collection;

final readonly class BscCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const METHOD = 'USDT_BEP20';

    public const PAYMENT_CURRENCY = 'USDT';

    public const IDENTITY = PaymentProvider::MANUAL.'|'.self::METHOD;

    public function __construct(
        private BscReadiness $readiness,
        private BscPaymentReceiverPool $receiverPool,
        private BscTokenAmount $tokenAmount,
    ) {}

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        return CheckoutPaymentPreflight::withState(
            $context,
            $this->readiness->check($context->currentSetting),
        );
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $context->preflight->assertMatches(new CheckoutPaymentPreflightContext(
            currentSetting: $context->lockedSetting,
            requestedProvider: PaymentProvider::MANUAL,
            requestedMethod: self::METHOD,
        ));

        $report = $context->preflight->stateAs(BscReadinessReport::class);
        $this->readiness->assertCheckoutSnapshot($report, $context->lockedSetting);

        $baseAmount = $this->baseAmount($context->items, $context->products);
        $expiresAt = now()->addMinutes(
            $context->lockedSetting?->bscPaymentTtlMinutes()
                ?? ManualPaymentSetting::DEFAULT_BSC_PAYMENT_TTL_MINUTES,
        );
        $receiver = $this->receiverPool->selectSnapshot();

        $snapshot = new BscCheckoutPaymentSnapshot(
            provider: PaymentProvider::MANUAL,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $baseAmount,
            expiresAt: $expiresAt,
            receiver: $receiver,
            baseAmountMicro: $baseAmount,
            payableAmountMicro: $baseAmount,
            expectedTokenUnits: $this->tokenAmount->expectedTokenUnits(
                $baseAmount,
                $receiver->tokenDecimals,
            ),
            verificationStatus: PaymentBscDetail::STATUS_AWAITING_TRANSFER,
            requiredConfirmations: $context->lockedSetting?->bscMinConfirmations()
                ?? ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS,
            requiresFinalizedBlock: (bool) ($context->lockedSetting?->bsc_require_finalized_block ?? false),
            confirmationGraceMinutes: $context->lockedSetting?->bscConfirmationGraceMinutes()
                ?? ManualPaymentSetting::DEFAULT_BSC_CONFIRMATION_GRACE_MINUTES,
        );

        return new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $expiresAt,
            provider: PaymentProvider::MANUAL,
            method: self::METHOD,
            paymentCurrency: self::PAYMENT_CURRENCY,
            amount: $baseAmount,
            paymentExpiresAt: $expiresAt,
            railSnapshot: $snapshot,
            postCreationBehavior: CheckoutPostCreationBehavior::BscInstructions,
        );
    }

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $products
     */
    private function baseAmount(Collection $items, Collection $products): int
    {
        return $items->sum(function ($item) use ($products): int {
            $price = $products[$item->product_id]->usdt_price_amount;
            if ($price === null) {
                throw new InvalidCheckout('A cart item has no USDT price.');
            }

            return (int) $price * (int) $item->quantity;
        });
    }
}
