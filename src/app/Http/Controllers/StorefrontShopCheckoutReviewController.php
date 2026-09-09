<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontCartPageBuilder;
use App\Storefront\StorefrontCheckoutReviewPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontShopCheckoutReviewController extends Controller
{
    public function __invoke(Request $request, StorefrontCartPageBuilder $pages): Response|RedirectResponse
    {
        $review = $pages->checkoutReview($request->session());

        if ($review === null) {
            return redirect()->route('storefront.shop.index');
        }

        return $this->render($review);
    }

    public function product(
        Request $request,
        Product $product,
        StorefrontCartPageBuilder $pages,
    ): Response {
        abort_unless($product->isPublicVisible(), 404);

        return $this->render($pages->directCheckoutReview(
            $product,
            $request->query('quantity'),
        ));
    }

    private function render(StorefrontCheckoutReviewPresentation $review): Response
    {
        return response()
            ->view('storefront.shop-checkout', $review->viewData())
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
