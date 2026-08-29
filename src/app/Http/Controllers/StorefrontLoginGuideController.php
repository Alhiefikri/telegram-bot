<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontSeo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontLoginGuideController extends Controller
{
    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontSeo $seo,
    ) {}

    public function index(): View
    {
        $brand = $this->storefront->brand();
        $guides = collect(config('storefront.guide_catalog', []))
            ->map(fn (array $guide): array => [
                ...$guide,
                'url' => route($guide['route']),
            ])
            ->all();

        $metadata = $this->seo->guides(route('storefront.guides.index'));

        return view('storefront.guides', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'storefront' => $this->storefront,
            'guides' => $guides,
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
        ]);
    }

    public function show(Request $request): View
    {
        $brand = $this->storefront->brand();
        $guide = config('storefront.login_guide');
        $guide['images'] = array_map(static fn (string $path): string => asset($path), $guide['images']);
        $returnUrl = $this->invoiceReturnUrl($request);
        $invoiceNumber = $this->invoiceNumber($request);

        $metadata = $this->seo->loginGuide(
            route('storefront.login-guide'),
            privateContext: $returnUrl !== null || $invoiceNumber !== null,
        );

        return view('storefront.login-guide', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'guide' => $guide,
            'invoiceReturnUrl' => $returnUrl,
            'invoiceNumber' => $invoiceNumber,
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
        ]);
    }

    private function invoiceReturnUrl(Request $request): ?string
    {
        $returnUrl = $request->query('return');

        if (! is_string($returnUrl)) {
            return null;
        }

        if (! str_starts_with($returnUrl, '/') || str_starts_with($returnUrl, '//')) {
            return null;
        }

        $path = parse_url($returnUrl, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/shop/orders/')) {
            return null;
        }

        return $returnUrl;
    }

    private function invoiceNumber(Request $request): ?string
    {
        $invoice = $request->query('invoice');

        if (! is_string($invoice)) {
            return null;
        }

        $invoice = str_replace(["\r", "\n"], ' ', trim($invoice));

        return $invoice !== '' ? $invoice : null;
    }
}
