<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Http\Requests\StorefrontShopCheckoutRequest;
use App\Models\Product;
use App\Storefront\StorefrontShopCheckout;
use Illuminate\Http\RedirectResponse;

class StorefrontShopCheckoutController extends Controller
{
    public function __invoke(StorefrontShopCheckoutRequest $request, StorefrontShopCheckout $checkout): RedirectResponse
    {
        $validated = $request->validated();
        $product = Product::query()->whereKey($request->integer('product_id'))->firstOrFail();
        $buyer = $request->buyerHandle();

        try {
            $result = $checkout->start(
                product: $product,
                quantity: (int) $validated['quantity'],
                displayName: $buyer['display_name'],
                telegramUsername: $buyer['telegram_username'],
            );
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock $exception) {
            return back()
                ->withInput()
                ->withErrors(['product_id' => $exception->getMessage()]);
        }

        return redirect()->to($result->orderUrl);
    }
}
