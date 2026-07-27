<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontLoginGuideController extends Controller
{
    public function __construct(private readonly StorefrontPresenter $storefront) {}

    public function index(): View
    {
        $brand = $this->storefront->brand();
        $guides = collect(config('storefront.guide_catalog', []))
            ->map(fn (array $guide): array => [
                ...$guide,
                'url' => route($guide['route']),
            ])
            ->all();

        return view('storefront.guides', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'storefront' => $this->storefront,
            'guides' => $guides,
            'pageTitle' => 'Pusat Panduan | '.$brand,
            'pageDescription' => 'Pilih panduan penggunaan produk Lhie Store, termasuk login ChatGPT melalui Outlook, iCloud, dan MFA.',
            'canonicalUrl' => route('storefront.guides.index'),
        ]);
    }

    public function show(Request $request): View
    {
        $brand = $this->storefront->brand();
        $guide = config('storefront.login_guide');
        $guide['images'] = array_map(static fn (string $path): string => asset($path), $guide['images']);
        $returnUrl = $this->invoiceReturnUrl($request);
        $invoiceNumber = $this->invoiceNumber($request);

        return view('storefront.login-guide', [
            'brand' => $brand,
            'copy' => config('storefront'),
            'guide' => $guide,
            'invoiceReturnUrl' => $returnUrl,
            'invoiceNumber' => $invoiceNumber,
            'pageTitle' => 'Panduan Login ChatGPT | '.$brand,
            'pageDescription' => 'Panduan masuk dan mengamankan akun ChatGPT dari Lhie Store memakai Outlook, iCloud, dan Google Authenticator.',
            'canonicalUrl' => route('storefront.login-guide'),
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
