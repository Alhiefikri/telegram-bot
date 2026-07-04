<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;

class StorefrontManualCheckoutController extends Controller
{
    public function __invoke(Product $product): RedirectResponse
    {
        return redirect()->route('storefront.products.payment', $product);
    }
}
