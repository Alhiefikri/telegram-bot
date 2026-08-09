<?php

namespace App\Storefront;

use App\Models\Order;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationStatus;

class StorefrontShopOrderPresenter
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontShopSignedInvoice $signedInvoices,
        private readonly StorefrontSupport $support,
        private readonly StorefrontUrl $urls,
        private readonly StorefrontPaymentTerminalCopy $terminalCopy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(Order $order, bool $canRevealCredentials = false, ?string $accessKey = null): array
    {
        $invoice = $this->signedInvoices->read($order);
        $brand = $this->storefront->brand();

        return [
            'order' => $invoice->order,
            'payment' => $invoice->payment,
            'storefront' => $this->storefront,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => $this->urls->catalogUrl(),
            'pageTitle' => 'Order '.$invoice->order->invoice_number.' | '.$brand,
            'pageDescription' => 'Status checkout QRIS untuk invoice '.$invoice->order->invoice_number.'.',
            'canonicalUrl' => $invoice->redirectUrl,
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'supportContext' => $this->support->order($invoice->order),
            'statusUrl' => $invoice->statusUrl,
            'binanceOrderIdUrl' => $invoice->binanceOrderIdUrl,
            'redirectUrl' => $invoice->redirectUrl,
            'cancelUrl' => $invoice->cancelUrl,
            'canCancel' => $invoice->canCancel,
            'isPaid' => $invoice->isPaid(),
            'isPendingPayment' => $invoice->isPendingPayment(),
            'isTerminal' => $invoice->isTerminal(),
            'effectivePaymentStatus' => $this->terminalCopy->effectivePaymentStatus($invoice->order, $invoice->payment),
            'statusMessage' => $invoice->statusMessage,
            'payableAmount' => $invoice->payableAmount,
            'payableAmountFromProvider' => $invoice->payableAmountFromProvider,
            'isQrisPayable' => $invoice->isQrisPayable,
            'qrisReadinessState' => $invoice->qrisReadinessState,
            'qrisReadinessTitle' => $invoice->qrisReadinessTitle,
            'qrisReadinessMessage' => $invoice->qrisReadinessMessage,
            'qrisRetryLabel' => $invoice->qrisRetryLabel,
            'qrisImageDataUri' => $invoice->qrisImageDataUri,
            'qrisImageSource' => $invoice->qrisImageSource,
            'payableAmountDisplay' => $invoice->payableAmountDisplay,
            'isBinancePay' => $invoice->payment?->provider === 'manual' && $invoice->payment?->method === 'BINANCE_PAY_ID',
            'isManualQris' => $invoice->payment?->provider === 'manual' && $invoice->payment?->method === 'QR_MANUAL',
            'isHostedPakasir' => $invoice->payment?->provider === 'pakasir' && $invoice->payment?->method === 'hosted',
            'binanceVerificationMode' => $invoice->binanceVerificationStatus?->mode ?? 'amount_unique',
            'binanceVerificationStatus' => $this->binanceStatus($invoice->binanceVerificationStatus),
            'binanceVerificationAutomatic' => ($invoice->binanceVerificationStatus?->automatic ?? false)
                && $invoice->binanceVerificationStatus?->outcome !== BinanceVerificationOutcome::NotConfigured,
            'binanceVerificationClosed' => $invoice->binanceVerificationStatus?->closed() ?? false,
            'binanceReceiverLabel' => $invoice->payment?->binance_receiver_type === 'uid'
                ? 'Binance UID'
                : ($invoice->payment?->binance_receiver_type === 'pay_id' ? 'Binance Pay ID' : (filled(config('services.binance.pay_id')) ? 'Binance Pay ID' : 'Binance UID')),
            'binancePayId' => (string) ($invoice->payment?->binance_receiver_value ?: (config('services.binance.pay_id') ?: config('services.binance.pay_uid'))),
            'hostedPaymentUrl' => $invoice->hostedPaymentUrl,
            'accountGroups' => $invoice->accountGroups,
            'usageGuides' => $invoice->usageGuides,
            'accountCopyText' => $invoice->accountCopyText,
            'paidDeliveryType' => $invoice->paidDeliveryType,
            'manualFulfillmentStatus' => $invoice->manualFulfillmentStatus,
            'canRevealCredentials' => $canRevealCredentials,

            'accessKey' => $accessKey,
            'orderAccessUrl' => route('storefront.order-access.create'),
        ];
    }

    private function binanceStatus(?BinanceVerificationStatus $status): string
    {
        if ($status === null) {
            return 'awaiting_submission';
        }

        return match ($status->outcome) {
            BinanceVerificationOutcome::Confirmed,
            BinanceVerificationOutcome::AlreadyPaid => 'confirmed',
            BinanceVerificationOutcome::Mismatch,
            BinanceVerificationOutcome::TransactionAlreadyUsed => 'mismatch',
            BinanceVerificationOutcome::IncompleteWindow => 'checking',
            default => $status->outcome->value,
        };
    }
}
