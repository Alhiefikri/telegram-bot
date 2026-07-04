<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;

class StorefrontProductController extends Controller
{
    public function __construct(private readonly StorefrontPresenter $storefront) {}

    public function __invoke(Product $product): View
    {
        abort_unless($product->isPublicVisible(), 404);

        return view('storefront.product', [
            'product' => $product,
            'stock' => $this->storefront->stockAvailable($product),
        ]);
    }
}
