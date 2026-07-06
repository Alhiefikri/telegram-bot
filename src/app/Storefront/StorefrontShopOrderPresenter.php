<?php

namespace App\Storefront;

use App\Models\Order;

class StorefrontShopOrderPresenter
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontShopSignedInvoice $signedInvoices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(Order $order): array
    {
        $invoice = $this->signedInvoices->read($order);
        $brand = $this->storefront->brand();

        return [
            'order' => $invoice->order,
            'payment' => $invoice->payment,
            'storefront' => $this->storefront,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.shop.index'),
            'pageTitle' => 'Order '.$invoice->order->invoice_number.' | '.$brand,
            'pageDescription' => 'Status checkout QRIS untuk invoice '.$invoice->order->invoice_number.'.',
            'canonicalUrl' => $invoice->redirectUrl,
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'statusUrl' => $invoice->statusUrl,
            'redirectUrl' => $invoice->redirectUrl,
            'isPaid' => $invoice->isPaid(),
            'isPendingPayment' => $invoice->isPendingPayment(),
            'payableAmount' => $invoice->payableAmount,
            'payableAmountFromProvider' => $invoice->payableAmountFromProvider,
            'qrisImageDataUri' => $invoice->qrisImageDataUri,
            'hostedPaymentUrl' => $invoice->hostedPaymentUrl,
            'accountGroups' => $invoice->accountGroups,
        ];
    }
}
