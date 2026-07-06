<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Storefront\StorefrontShopOrderPresenter;
use Illuminate\Contracts\View\View;

class StorefrontShopOrderController extends Controller
{
    public function __invoke(Order $order, StorefrontShopOrderPresenter $presenter): View
    {
        return view('storefront.shop-order', $presenter->viewData($order));
    }
}
