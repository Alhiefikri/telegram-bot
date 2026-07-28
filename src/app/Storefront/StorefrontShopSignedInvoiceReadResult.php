<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;

class StorefrontShopSignedInvoiceReadResult
{
    /**
     * @param  list<array{product_name: string, secrets: list<array{label: string, text: string, parts: ?array{email: string, password: string, mfa: string}}>, copy_text: string, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>  $accountGroups
     * @param  list<array{title: string, url: string}>  $usageGuides
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?Payment $payment,
        public readonly StorefrontShopSignedInvoiceState $state,
        public readonly string $statusUrl,
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
        public readonly ?string $hostedPaymentUrl,
        public readonly array $accountGroups,
        public readonly array $usageGuides,
        public readonly string $accountCopyText,
        public readonly string $paidDeliveryType,
        public readonly string $manualFulfillmentStatus,
        public readonly string $statusMessage,
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
