<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontCartPageBuilder;
use App\Storefront\StorefrontCheckoutReviewPresentation;
use App\Storefront\StorefrontFunnelEvents;
use App\Storefront\StorefrontUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontShopCheckoutReviewController extends Controller
{
    public function __construct(
        private readonly StorefrontFunnelEvents $events,
        private readonly StorefrontUrl $urls,
    ) {}

    public function __invoke(Request $request, StorefrontCartPageBuilder $pages): Response|RedirectResponse
    {
        $review = $pages->checkoutReview($request->session());

        if ($review === null) {
            return redirect()->route('storefront.shop.index');
        }

        if (! $review->isDirect()) {
            $this->events->cartCheckoutOpened($review->itemCount);
        }

        return $this->render($review);
    }

    public function product(
        Request $request,
        Product $product,
        StorefrontCartPageBuilder $pages,
    ): Response {
        abort_unless($product->isPublicVisible(), 404);

        $returnUrl = $this->urls->checkoutReturnUrl($request, $product);
        $this->events->checkoutOpened($product, [
            'source' => $this->urls->checkoutPageSource($returnUrl),
        ]);

        return $this->render($pages->directCheckoutReview(
            $product,
            $request->query('quantity'),
            $returnUrl,
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
