<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontFunnelEvents;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontShopOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontShopOrderController extends Controller
{
    public function __invoke(Request $request, Order $order, StorefrontShopOrderPresenter $presenter, StorefrontOrderAccess $access, StorefrontFunnelEvents $events): Response
    {
        $access->grantFromIssuedKey($request, $order);
        $viewData = $presenter->viewData(
            $order,
            canRevealCredentials: (! $access->hasKey($order) && blank($order->checkout_key)) || $access->canAccess($request, $order),
            accessKey: $access->issuedKey($request, $order),
        );

        if (($viewData['isPaid'] ?? false) === true) {
            $events->paymentVerified($viewData['order'], $viewData['payment'], ['source' => 'invoice_opened']);
        }

        if (($viewData['canRevealCredentials'] ?? false) === true && ! empty($viewData['accountGroups'])) {
            $events->credentialsOpened($viewData['order'], $viewData['payment'], [
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
