<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontCartPageBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontShopCheckoutReviewController extends Controller
{
    public function __invoke(Request $request, StorefrontCartPageBuilder $pages): View|RedirectResponse
    {
        $data = $pages->viewData($request->session());

        if ($data['cart'] === null || $data['cartItems']->isEmpty()) {
            return redirect()->route('storefront.shop.index');
        }

        return view('storefront.shop-checkout', $data);
    }
}
