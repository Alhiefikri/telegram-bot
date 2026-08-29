<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class StorefrontRobotsController extends Controller
{
    public function __invoke(): Response
    {
        $sitemapUrl = route('storefront.sitemap');
        if ((bool) config('app.force_https') || app()->environment('production')) {
            $sitemapUrl = preg_replace('/\Ahttp:/i', 'https:', $sitemapUrl) ?: $sitemapUrl;
        }

        $body = implode("\n", [
            'User-agent: *',
            'Disallow: /shop/cart',
            'Disallow: /shop/checkout',
            'Disallow: /shop/orders/',
            'Disallow: /akses-pesanan',
            'Disallow: /akun',
            'Disallow: /akun/',
            'Disallow: /admin',
            'Disallow: /admin/',
            'Disallow: /telegram-mini-app',
            'Disallow: /api/',
            'Disallow: /api/webhook/',
            'Disallow: /storefront/events',
            'Disallow: /*?q=',
            'Disallow: /*?*q=',
            'Disallow: /*?checkout=',
            'Disallow: /*?*checkout=',
            'Disallow: /*?from=',
            'Disallow: /*?*from=',
            'Sitemap: '.$sitemapUrl,
            '',
        ]);

        return response($body)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
