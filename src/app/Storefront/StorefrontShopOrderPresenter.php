<?php

namespace App\Storefront;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\Bsc\BscVerificationStatus;
use Carbon\Carbon;

class StorefrontShopOrderPresenter
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontShopSignedInvoice $signedInvoices,
        private readonly StorefrontSupport $support,
        private readonly StorefrontUrl $urls,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontPaymentTerminalCopy $terminalCopy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(Order $order, bool $canRevealCredentials = false, ?string $accessKey = null): array
    {
        $invoice = $this->signedInvoices->read($order);
        $brand = $this->storefront->brand();
        $paymentDeadline = $invoice->qrisEffectiveExpiresAt
            ?? $this->localPaymentDeadline($invoice);
        $bscStatus = $this->bscStatus($invoice->bscVerificationStatus);
        $paymentKind = match (strtoupper((string) $invoice->payment?->method)) {
            'BINANCE_PAY_ID' => 'binance',
            'USDT_BEP20' => 'bsc',
            default => 'qris',
        };
        $pollIntervalMs = max(
            20_000,
            (int) config('services.storefront.payment_status_poll_interval_seconds', 20) * 1_000,
        );
        $pollMaxAttempts = max(1, (int) config('services.storefront.payment_status_poll_max_attempts', 9));
        $pollMaxDurationMs = max(
            $pollIntervalMs,
            (int) config('services.storefront.payment_status_poll_max_duration_seconds', 180) * 1_000,
        );

        return [
            'order' => $invoice->order,
            'payment' => $invoice->payment,
            'storefront' => $this->storefront,
            'brand' => $brand,
            'storefrontLocale' => $this->terminalCopy->locale(),
            'paymentStatusCopy' => [
                $paymentKind => $this->terminalCopy->statusUiCopy($paymentKind),
            ],
            'copy' => config('storefront'),
            'homeUrl' => $this->urls->catalogUrl(),
            'pageTitle' => 'Order '.$invoice->order->invoice_number.' | '.$brand,
            'pageDescription' => 'Status checkout QRIS untuk invoice '.$invoice->order->invoice_number.'.',
            'canonicalUrl' => $invoice->redirectUrl,
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'supportContext' => $this->support->order($invoice->order),
            'statusUrl' => $invoice->statusUrl,
            'qrisImageFallbackUrl' => $invoice->isPendingPayment()
                && $invoice->isQrisPayable
                && strtolower((string) $invoice->payment?->provider) === 'klikqris'
                ? $this->invoiceUrls->qrisImage($invoice->order)
                : null,
            'binanceOrderIdUrl' => $invoice->binanceOrderIdUrl,
            'bscTxHashUrl' => $invoice->bscTxHashUrl,
            'redirectUrl' => $invoice->redirectUrl,
            'cancelUrl' => $invoice->cancelUrl,
            'canCancel' => $invoice->canCancel,
            'isPaid' => $invoice->isPaid(),
            'isPendingPayment' => $invoice->isPendingPayment(),
            'isTerminal' => $invoice->isTerminal(),
            'orderStatusLabel' => $this->terminalCopy->orderStatusLabel($invoice->order->order_status),
            'effectivePaymentStatus' => $this->terminalCopy->effectivePaymentStatus($invoice->order, $invoice->payment),
            'paymentStatusPollIntervalMs' => $pollIntervalMs,
            'paymentStatusPollMaxAttempts' => $pollMaxAttempts,
            'paymentStatusPollMaxDurationMs' => $pollMaxDurationMs,
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
            'paymentDeadline' => $paymentDeadline,
            'paymentDeadlineTimestamp' => $paymentDeadline?->toIso8601String(),
            'isBinancePay' => $invoice->payment?->provider === 'manual' && $invoice->payment?->method === 'BINANCE_PAY_ID',
            'isBscPayment' => $invoice->payment?->provider === 'manual' && $invoice->payment?->method === 'USDT_BEP20',
            'isManualQris' => $invoice->payment?->provider === 'manual' && $invoice->payment?->method === 'QR_MANUAL',
            'isHostedPakasir' => $invoice->payment?->provider === 'pakasir' && $invoice->payment?->method === 'hosted',
            'binanceVerificationMode' => $invoice->binanceVerificationStatus?->mode ?? 'amount_unique',
            'binanceVerificationStatus' => $this->binanceStatus($invoice->binanceVerificationStatus),
            'binanceVerificationAutomatic' => ($invoice->binanceVerificationStatus?->automatic ?? false)
                && $invoice->binanceVerificationStatus?->outcome !== BinanceVerificationOutcome::NotConfigured,
            'binanceVerificationClosed' => $invoice->binanceVerificationStatus?->closed() ?? false,
            'binanceReceiverLabel' => $this->terminalCopy->binanceReceiverLabel(
                $invoice->payment?->binance_receiver_type,
                filled(config('services.binance.pay_id')),
            ),
            'binancePayId' => (string) ($invoice->payment?->binance_receiver_value ?: (config('services.binance.pay_id') ?: config('services.binance.pay_uid'))),
            'bscReceiverAddress' => (string) ($invoice->payment?->bscDetail?->receiver_address ?? ''),
            'bscPayableAmount' => $invoice->payment?->bscDetail?->payable_amount_micro,
            'bscBaseAmount' => $invoice->payment?->bscDetail?->base_amount_micro,
            'bscNetworkLabel' => $this->terminalCopy->translate('storefront.invoice.bsc.network'),
            'bscVerificationStatus' => $bscStatus,
            'bscStatusLabel' => $this->terminalCopy->bscStatusLabel($bscStatus),
            'bscVerificationOutcome' => $invoice->bscVerificationStatus?->outcome->value,
            'bscConfirmations' => $invoice->bscVerificationStatus?->confirmations ?? 0,
            'bscRequiredConfirmations' => $invoice->bscVerificationStatus?->requiredConfirmations
                ?? ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS,
            'bscExplorerUrl' => $invoice->bscExplorerUrl,
            'bscVerificationClosed' => in_array($invoice->bscVerificationStatus?->outcome, [
                BscVerificationOutcome::Expired,
                BscVerificationOutcome::Mismatch,
                BscVerificationOutcome::ManualReview,
                BscVerificationOutcome::TransactionAlreadyUsed,
            ], true) || $invoice->isTerminal(),
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

    private function bscStatus(?BscVerificationStatus $status): string
    {
        if ($status === null) {
            return 'awaiting_transfer';
        }

        return match ($status->outcome) {
            BscVerificationOutcome::Confirmed,
            BscVerificationOutcome::AlreadyPaid => 'confirmed',
            BscVerificationOutcome::Mismatch => 'mismatch',
            BscVerificationOutcome::ManualReview => 'manual_review',
            BscVerificationOutcome::TransactionAlreadyUsed => 'transaction_already_used',
            default => $status->outcome->value,
        };
    }

    /**
     * Deadline shown for non-KlikQRIS provider outcomes and as the local
     * checkout fallback when a KlikQRIS payable snapshot is not available.
     * A non-payable KlikQRIS Payment never exposes its provider deadline.
     */
    private function localPaymentDeadline(StorefrontShopSignedInvoiceReadResult $invoice): ?Carbon
    {
        $payment = $invoice->payment;
        if ($payment !== null && strtolower((string) $payment->provider) === 'klikqris') {
            return $payment->expired_at ?? $invoice->order->expires_at;
        }

        return $payment?->provider_expires_at
            ?? $payment?->expired_at
            ?? $invoice->order->expires_at;
    }
}
