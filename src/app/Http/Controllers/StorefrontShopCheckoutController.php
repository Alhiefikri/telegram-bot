<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Http\Requests\StorefrontShopCheckoutRequest;
use App\Models\Order;
use App\Models\Product;
use App\Storefront\StorefrontCheckoutMaintenanceCopy;
use App\Storefront\StorefrontFunnelEvents;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StorefrontShopCheckoutController extends Controller
{
    public function __invoke(StorefrontShopCheckoutRequest $request, StorefrontShopCheckout $checkout, StorefrontOrderAccess $orderAccess, StorefrontFunnelEvents $events): RedirectResponse
    {
        $product = Product::query()->find((int) $request->input('product_id'));
        if ($product !== null) {
            $events->checkoutOpened($product, ['source' => 'checkout_submit']);
        }

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
        } catch (CheckoutMaintenanceActive $exception) {
            $message = StorefrontCheckoutMaintenanceCopy::notice($exception->state);
            $events->checkoutSubmitted($product, null, [
                'status_endpoint_result' => 'rejected',
                'failure_kind' => $exception::class,
            ]);

            return redirect()
                ->to($request->checkoutRecoveryUrl())
                ->withInput()
                ->with('storefront_checkout_error', $message)
                ->withErrors(['product_id' => $message]);
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock $exception) {
            $events->checkoutSubmitted($product, null, [
                'status_endpoint_result' => 'rejected',
                'failure_kind' => $exception::class,
            ]);

            return redirect()
                ->to($request->checkoutRecoveryUrl())
                ->withInput()
                ->with('storefront_checkout_error', $exception->getMessage())
                ->withErrors(['product_id' => $exception->getMessage()]);
        }

        if ($result->orderId !== null && $result->recoveryKey !== null) {
            $order = Order::find($result->orderId);
            if ($order !== null) {
                $orderAccess->rememberIssuedKey($request, $order, $result->recoveryKey);
            }
        }

        $events->checkoutSubmitted($product, $result->orderId === null ? null : Order::find($result->orderId), [
            'status_endpoint_result' => 'accepted',
        ]);

        return redirect()->to($result->redirectUrl);
    }
}
