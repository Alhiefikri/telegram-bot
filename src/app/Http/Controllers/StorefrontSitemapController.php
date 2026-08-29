<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontSitemap;
use Illuminate\Http\Response;

final class StorefrontSitemapController extends Controller
{
    public function __invoke(StorefrontSitemap $sitemap): Response
    {
        return response($sitemap->render())
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
