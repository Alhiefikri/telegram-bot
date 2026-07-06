<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Http\Requests\StorefrontShopCheckoutRequest;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Http\RedirectResponse;

class StorefrontShopCheckoutController extends Controller
{
    public function __invoke(StorefrontShopCheckoutRequest $request, StorefrontShopCheckout $checkout): RedirectResponse
    {
        try {
            $result = $checkout->start(StorefrontShopCheckoutInput::fromValidated($request->validated()));
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock $exception) {
            return back()
                ->withInput()
                ->withErrors(['product_id' => $exception->getMessage()]);
        }

        return redirect()->to($result->redirectUrl);
    }
}
