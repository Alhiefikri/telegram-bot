<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Storefront\StorefrontProductPresenter;
use Illuminate\Http\JsonResponse;

class StorefrontProductModalController extends Controller
{
    public function __construct(
        private readonly StorefrontProductPresenter $products,
    ) {}

    public function __invoke(Product $product): JsonResponse
    {
        abort_unless($product->isPublicVisible(), 404);

        $fallbackUrl = route('storefront.products.show', $product);
        $presentation = $this->products->present($product);
        $modalPayload = $presentation->modalPayload();

        if ($modalPayload === null) {
            return $this->jsonResponse([
                'status' => 'fallback',
                'product' => null,
                'reason' => 'checkout_unavailable',
                'fallback_url' => $fallbackUrl,
            ]);
        }

        return $this->jsonResponse([
            'status' => 'ready',
            'product' => $modalPayload,
            'fallback_url' => $fallbackUrl,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonResponse(array $payload): JsonResponse
    {
        return response()->json($payload)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
