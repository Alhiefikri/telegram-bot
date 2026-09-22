<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontController extends Controller
{
    public function __construct(private readonly StorefrontResponseCache $storefrontResponseCache) {}

    public function __invoke(Request $request): Response
    {
        $search = StorefrontCatalogSearch::fromRequestQuery($request->query('q'));
        $response = response($this->storefrontResponseCache->get($search))
            ->header('Content-Type', 'text/html; charset=UTF-8');

        if ($search === '') {
            return $response
                ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=60')
                ->header('CDN-Cache-Control', 'public, max-age=300, stale-while-revalidate=60')
                ->header('Surrogate-Control', 'max-age=300');
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
