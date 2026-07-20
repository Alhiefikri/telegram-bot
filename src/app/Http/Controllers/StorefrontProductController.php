<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontProductController extends Controller
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontUrl $urls,
    ) {}

    public function __invoke(Request $request, Product $product): View
    {
        abort_unless($product->isPublicVisible(), 404);

        return view('storefront.product', $this->storefront->productPageViewData(
            product: $product,
            stock: $this->storefront->stockAvailable($product),
            checkoutRequested: $request->boolean('checkout'),
            returnUrl: $this->urls->productReturnUrl($request, $product),
        ));
    }
}
