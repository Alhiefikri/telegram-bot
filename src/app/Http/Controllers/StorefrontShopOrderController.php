<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontShopOrderPresenter;
use Illuminate\Http\Response;

class StorefrontShopOrderController extends Controller
{
    public function __invoke(Order $order, StorefrontShopOrderPresenter $presenter): Response
    {
        return response()
            ->view('storefront.shop-order', $presenter->viewData($order))
            ->header('Referrer-Policy', 'no-referrer');
    }
}
