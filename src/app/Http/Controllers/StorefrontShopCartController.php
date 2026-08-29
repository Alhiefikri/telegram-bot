<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Http\Requests\StorefrontShopCartAddRequest;
use App\Http\Requests\StorefrontShopCartUpdateRequest;
use App\Models\Product;
use App\Services\CartService;
use App\Storefront\StorefrontCartPageBuilder;
use App\Storefront\StorefrontGuestCart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorefrontShopCartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function index(Request $request, StorefrontCartPageBuilder $pages): View
    {
        return view('storefront.shop-cart', $pages->viewData($request->session()));
    }

    public function store(StorefrontShopCartAddRequest $request, StorefrontGuestCart $guestCart): RedirectResponse
    {
        try {
            $product = $this->product((int) $request->validated('product_id'));
            DB::transaction(function () use ($request, $guestCart, $product): void {
                $cart = $guestCart->active($request->session());
                $this->carts->addItem($cart, $product, (int) $request->validated('quantity'));
            });

            return redirect()
                ->route('storefront.shop.cart')
                ->with('storefront_cart_notice', 'Produk ditambahkan ke keranjang.');
        } catch (InvalidCartState|InsufficientStock $exception) {
            return $this->cartError($exception->getMessage());
        }
    }

    public function update(StorefrontShopCartUpdateRequest $request, int $product, StorefrontGuestCart $guestCart): RedirectResponse
    {
        try {
            $cart = $guestCart->current($request->session());
            if ($cart === null) {
                throw new InvalidCartState('Keranjang sudah kedaluwarsa atau belum dibuat.');
            }

            $this->carts->setItemQuantity(
                $cart,
                $this->product($product),
                (int) $request->validated('quantity'),
            );

            return redirect()
                ->route('storefront.shop.cart')
                ->with('storefront_cart_notice', 'Jumlah produk diperbarui.');
        } catch (InvalidCartState|InsufficientStock $exception) {
            return $this->cartError($exception->getMessage());
        }
    }

    public function destroy(Request $request, int $product, StorefrontGuestCart $guestCart): RedirectResponse
    {
        try {
            $cart = $guestCart->current($request->session());
            if ($cart === null) {
                throw new InvalidCartState('Keranjang sudah kedaluwarsa atau belum dibuat.');
            }

            $this->carts->removeItem($cart, $this->product($product));

            return redirect()
                ->route('storefront.shop.cart')
                ->with('storefront_cart_notice', 'Produk dihapus dari keranjang.');
        } catch (InvalidCartState|InsufficientStock $exception) {
            return $this->cartError($exception->getMessage());
        }
    }

    private function product(int $productId): Product
    {
        $product = Product::withTrashed()->find($productId);
        if ($product === null) {
            throw new InvalidCartState('Produk tidak ditemukan atau sudah tidak tersedia.');
        }

        return $product;
    }

    private function cartError(string $message): RedirectResponse
    {
        return redirect()
            ->route('storefront.shop.cart')
            ->with('storefront_cart_error', $message)
            ->withErrors(['cart' => $message]);
    }
}
