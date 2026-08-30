<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorefrontWaitlistRequest;
use App\Models\Product;
use App\Services\ProductWaitlistSubscriptionService;
use App\Storefront\StorefrontPageMetadata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontWaitlistController extends Controller
{
    public function store(
        StorefrontWaitlistRequest $request,
        Product $product,
        ProductWaitlistSubscriptionService $subscriptions,
    ): RedirectResponse {
        abort_unless($product->isPublicVisible(), 404);

        $subscriptions->subscribe($product, (string) $request->validated('email'));

        return redirect()
            ->to(route('storefront.products.show', ['product' => $product]).'#waitlist')
            ->with('storefront_waitlist_notice', config('storefront.waitlist.notices.pending_confirmation'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function confirm(
        Request $request,
        ProductWaitlistSubscriptionService $subscriptions,
    ): Response {
        return $this->resultResponse($subscriptions->confirm(
            $request,
            (string) $request->route('subscription'),
        ));
    }

    public function unsubscribe(
        Request $request,
        ProductWaitlistSubscriptionService $subscriptions,
    ): Response {
        return $this->resultResponse($subscriptions->unsubscribe(
            $request,
            (string) $request->route('subscription'),
        ));
    }

    /**
     * @param  array{status:string, productId:?int}  $result
     */
    private function resultResponse(array $result): Response
    {
        $copy = config('storefront.waitlist');
        $status = (string) ($result['status'] ?? ProductWaitlistSubscriptionService::RESULT_EXPIRED_LINK);
        $message = (string) ($copy['notices'][$status] ?? $copy['notices'][ProductWaitlistSubscriptionService::RESULT_EXPIRED_LINK]);
        $title = (string) ($copy['result_titles'][$status] ?? $copy['result_titles'][ProductWaitlistSubscriptionService::RESULT_EXPIRED_LINK]);
        $metadata = new StorefrontPageMetadata(
            title: $title.' | Lhie Store',
            description: $message,
            robots: 'noindex, nofollow',
            canonicalUrl: null,
            ogType: 'website',
            ogImage: url((string) config('storefront.product_placeholder')),
            ogImageAlt: 'Lhie Store',
            locale: (string) config('storefront.locale', 'id'),
        );

        return response()->view('storefront.waitlist-result', [
            'brand' => 'Lhie Store',
            'copy' => $copy,
            'metadata' => $metadata,
            'title' => $title,
            'message' => $message,
            'homeUrl' => route('storefront.home'),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
