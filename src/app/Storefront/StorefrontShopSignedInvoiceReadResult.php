<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;

class StorefrontShopSignedInvoiceReadResult
{
    /**
     * @param  list<array{product_name: string, secrets: list<string>}>  $accountGroups
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?Payment $payment,
        public readonly StorefrontShopSignedInvoiceState $state,
        public readonly string $statusUrl,
        public readonly string $redirectUrl,
        public readonly ?int $payableAmount,
        public readonly bool $payableAmountFromProvider,
        public readonly ?string $qrisImageDataUri,
        public readonly ?string $hostedPaymentUrl,
        public readonly array $accountGroups,
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
