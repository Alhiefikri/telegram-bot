<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Requests\StorefrontOrderAccessRequest;
use App\Models\Order;
use App\Storefront\StorefrontFunnelEvents;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontShopOrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontOrderAccessController extends Controller
{
    public function create(StorefrontPresenter $storefront): Response
    {
        $brand = $storefront->brand();

        return response()->view('storefront.order-access', [
            'storefront' => $storefront,
            'brand' => $brand,
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'pageTitle' => 'Akses Pesanan | '.$brand,
            'pageDescription' => 'Buka kembali data pesanan dengan nomor invoice dan recovery key.',
            'canonicalUrl' => route('storefront.order-access.create'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StorefrontOrderAccessRequest $request, StorefrontOrderAccess $access): RedirectResponse
    {
        $invoiceNumber = strtoupper(trim((string) $request->validated('invoice_number')));
        $order = Order::query()->where('invoice_number', $invoiceNumber)->first();

        if (! $order || $order->payment_status !== PaymentStatus::Paid || ! $access->authenticate($request, $order, (string) $request->validated('access_key'))) {
            return redirect()->route('storefront.order-access.create')
                ->with('storefront_order_access_error', 'Nomor invoice atau recovery key tidak cocok.');
        }

        return redirect()->route('storefront.order-access.show', $order);
    }

    public function show(Request $request, Order $order, StorefrontOrderAccess $access, StorefrontShopOrderPresenter $presenter, StorefrontFunnelEvents $events): Response
    {
        if ($order->payment_status !== PaymentStatus::Paid || ! $access->canAccess($request, $order)) {
            return response()->view('errors.403', [], 403)
                ->header('Cache-Control', 'no-store, private');
        }

        $viewData = $presenter->viewData($order, canRevealCredentials: true, accessKey: null);
        if (! empty($viewData['accountGroups'])) {
            $events->credentialsOpened($viewData['order'], $viewData['payment'], [
                'source' => 'recovery_key_access',
                'delivery_type' => $viewData['paidDeliveryType'] ?? null,
                'manual_fulfillment_status' => $viewData['manualFulfillmentStatus'] ?? null,
            ]);
        }

        return response()
            ->view('storefront.shop-order', $viewData)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
