<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontProductPaymentController extends Controller
{
    public function __construct(private readonly StorefrontPresenter $storefront) {}

    public function __invoke(Request $request, Product $product): View|RedirectResponse
    {
        abort_unless($product->isPublicVisible(), 404);

        $availableStock = $this->storefront->stockAvailable($product);
        if ($availableStock <= 0) {
            return redirect()
                ->route('storefront.products.show', $product)
                ->with('storefront_notice', 'Produk ini sedang habis. Hubungi admin untuk info restock.');
        }

        $quantity = max(1, min((int) $request->integer('qty', 1), $availableStock));
        $subtotal = (int) $product->price_amount * $quantity;

        return view('storefront.payment', $this->storefront->paymentPageViewData(
            product: $product,
            quantity: $quantity,
            subtotal: $subtotal,
        ));
    }
}
