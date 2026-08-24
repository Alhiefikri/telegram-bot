<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\Bsc\BscVerificationStatus;
use Carbon\CarbonImmutable;

class StorefrontShopSignedInvoiceReadResult
{
    /**
     * @param  list<array{product_name: string, secrets: list<array<string, mixed>>, copy_text: string, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>  $accountGroups
     * @param  list<array{title: string, url: string}>  $usageGuides
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?Payment $payment,
        public readonly StorefrontShopSignedInvoiceState $state,
        public readonly string $statusUrl,
        public readonly string $binanceOrderIdUrl,
        public readonly string $bscTxHashUrl,
        public readonly string $redirectUrl,
        public readonly string $cancelUrl,
        public readonly bool $canCancel,
        public readonly ?int $payableAmount,
        public readonly bool $payableAmountFromProvider,
        public readonly bool $isQrisPayable,
        public readonly string $qrisReadinessState,
        public readonly string $qrisReadinessTitle,
        public readonly string $qrisReadinessMessage,
        public readonly string $qrisRetryLabel,
        public readonly ?string $qrisImageDataUri,
        public readonly ?string $qrisImageSource,
        public readonly ?string $payableAmountDisplay,
        public readonly ?CarbonImmutable $qrisEffectiveExpiresAt,
        public readonly ?string $hostedPaymentUrl,
        public readonly array $accountGroups,
        public readonly array $usageGuides,
        public readonly string $accountCopyText,
        public readonly string $paidDeliveryType,
        public readonly string $manualFulfillmentStatus,
        public readonly string $statusMessage,
        public readonly ?BinanceVerificationStatus $binanceVerificationStatus = null,
        public readonly ?BscVerificationStatus $bscVerificationStatus = null,
        public readonly ?string $bscExplorerUrl = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->state === StorefrontShopSignedInvoiceState::Paid;
    }

    public function isPendingPayment(): bool
    {
        return $this->state === StorefrontShopSignedInvoiceState::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->state === StorefrontShopSignedInvoiceState::Terminal;
    }
}
