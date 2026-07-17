<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontShopOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontShopOrderController extends Controller
{
    public function __invoke(Request $request, Order $order, StorefrontShopOrderPresenter $presenter, StorefrontOrderAccess $access): Response
    {
        $access->grantFromIssuedKey($request, $order);

        return response()
            ->view('storefront.shop-order', $presenter->viewData(
                $order,
                canRevealCredentials: (! $access->hasKey($order) && blank($order->checkout_key)) || $access->canAccess($request, $order),
                accessKey: $access->issuedKey($request, $order),
            ))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
