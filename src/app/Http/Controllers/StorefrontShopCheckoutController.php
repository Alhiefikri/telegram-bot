<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Http\Requests\StorefrontShopCheckoutRequest;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StorefrontShopCheckoutController extends Controller
{
    public function __invoke(StorefrontShopCheckoutRequest $request, StorefrontShopCheckout $checkout): RedirectResponse
    {
        try {
            $session = $request->session();
            $checkoutClientKey = $session->get('storefront.checkout_client_key');
            if (! is_string($checkoutClientKey) || $checkoutClientKey === '') {
                $checkoutClientKey = Str::random(40);
                $session->put('storefront.checkout_client_key', $checkoutClientKey);
            }

            $result = $checkout->start(
                StorefrontShopCheckoutInput::fromValidated($request->validated()),
                $checkoutClientKey,
            );
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock $exception) {
            return redirect()
                ->route('storefront.shop.index')
                ->withInput()
                ->with('storefront_checkout_error', $exception->getMessage())
                ->withErrors(['product_id' => $exception->getMessage()]);
        }

        return redirect()->to($result->redirectUrl);
    }
}
