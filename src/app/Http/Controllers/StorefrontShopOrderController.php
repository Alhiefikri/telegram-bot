<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Throwable;

class StorefrontShopOrderController extends Controller
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly PakasirProviderAdapter $pakasir,
    ) {}

    public function __invoke(Order $order): View
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'payments']);
        $payment = $order->payments->sortByDesc('created_at')->first();
        $hostedPaymentUrl = null;

        if ($payment !== null && $payment->provider === 'pakasir') {
            try {
                $hostedPaymentUrl = $this->pakasir->hostedPaymentUrl($payment);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $brand = $this->storefront->brand();

        return view('storefront.shop-order', [
            'order' => $order,
            'payment' => $payment,
            'storefront' => $this->storefront,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.shop.index'),
            'pageTitle' => 'Order '.$order->invoice_number.' | '.$brand,
            'pageDescription' => 'Status checkout QRIS untuk invoice '.$order->invoice_number.'.',
            'canonicalUrl' => URL::signedRoute('storefront.shop.orders.show', $order),
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'statusUrl' => URL::signedRoute('storefront.shop.orders.status', $order),
            'hostedPaymentUrl' => $hostedPaymentUrl,
        ]);
    }
}
