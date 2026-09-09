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
use App\Storefront\StorefrontGuestCart;
use App\Storefront\StorefrontOrderAccess;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Http\RedirectResponse;

class StorefrontShopCheckoutController extends Controller
{
    public function __invoke(
        StorefrontShopCheckoutRequest $request,
        StorefrontShopCheckout $checkout,
        StorefrontOrderAccess $orderAccess,
        StorefrontFunnelEvents $events,
        StorefrontGuestCart $guestCart,
    ): RedirectResponse {
        $product = Product::query()->find((int) $request->input('product_id'));
        if ($product !== null) {
            $events->checkoutOpened($product, [
                'source' => 'checkout_submit',
                'payment_method' => $request->validated()['payment_method'] ?? null,
            ]);
        }
        $errorKey = $request->filled('product_id') ? 'product_id' : 'cart';

        try {
            $session = $request->session();
            $checkoutClientKey = $guestCart->clientKey($session);
            $result = $checkout->start(
                StorefrontShopCheckoutInput::fromValidated($request->validated()),
                $checkoutClientKey,
                $session,
            );
        } catch (CheckoutMaintenanceActive $exception) {
            $message = StorefrontCheckoutMaintenanceCopy::notice($exception->state);
            $events->checkoutSubmitted($product, null, [
                'status_endpoint_result' => 'rejected',
                'failure_kind' => $exception::class,
                'payment_method' => $request->validated()['payment_method'] ?? null,
            ]);

            $recoveryUrl = $request->filled('product_id')
                ? $request->checkoutRecoveryUrl()
                : route('storefront.shop.checkout.start');

            return redirect()
                ->to($recoveryUrl)
                ->withInput()
                ->with('storefront_checkout_error', $message)
                ->withErrors([$errorKey => $message]);
        } catch (InvalidCartState $exception) {
            $events->checkoutSubmitted($product, null, [
                'status_endpoint_result' => 'rejected',
                'failure_kind' => $exception::class,
                'payment_method' => $request->validated()['payment_method'] ?? null,
            ]);
            $recoveryUrl = $request->filled('product_id')
                ? $request->checkoutRecoveryUrl()
                : route('storefront.shop.cart');

            return redirect()
                ->to($recoveryUrl)
                ->withInput()
                ->with('storefront_checkout_error', $exception->getMessage())
                ->withErrors([$errorKey => $exception->getMessage()]);
        } catch (InvalidCheckout|InsufficientStock $exception) {
            $events->checkoutSubmitted($product, null, [
                'status_endpoint_result' => 'rejected',
                'failure_kind' => $exception::class,
                'payment_method' => $request->validated()['payment_method'] ?? null,
            ]);
            $recoveryUrl = $request->filled('product_id')
                ? $request->checkoutRecoveryUrl()
                : route('storefront.shop.checkout.start');

            return redirect()
                ->to($recoveryUrl)
                ->withInput()
                ->with('storefront_checkout_error', $exception->getMessage())
                ->withErrors([$errorKey => $exception->getMessage()]);
        }

        if ($result->orderId !== null && $result->recoveryKey !== null) {
            $order = Order::find($result->orderId);
            if ($order !== null) {
                $orderAccess->rememberIssuedKey($request, $order, $result->recoveryKey);
            }
        }

        $events->checkoutSubmitted($product, $result->orderId === null ? null : Order::find($result->orderId), [
            'status_endpoint_result' => 'accepted',
            'payment_method' => $request->validated()['payment_method'] ?? null,
        ]);

        return redirect()->to($result->redirectUrl);
    }
}
