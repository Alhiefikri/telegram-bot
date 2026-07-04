<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StorefrontProductPaymentController extends Controller
{
    private const QRIS_FEE = 401;

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
        $orderId = Str::upper('WEB'.Str::random(5));
        $subtotal = (int) $product->price_amount * $quantity;
        $total = $subtotal + self::QRIS_FEE;

        return view('storefront.payment', [
            'product' => $product,
            'orderId' => $orderId,
            'subtotal' => $subtotal,
            'qrisFee' => self::QRIS_FEE,
            'total' => $total,
            'quantity' => $quantity,
        ]);
    }
}
