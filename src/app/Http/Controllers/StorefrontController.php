<?php

namespace App\Http\Controllers;

use App\Catalog\CatalogBrowseQuery;
use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontController extends Controller
{
    public function __construct(private readonly StorefrontResponseCache $storefrontResponseCache) {}

    public function __invoke(Request $request): Response
    {
        $query = $request->query();
        $search = StorefrontCatalogSearch::fromRequestQuery($query['q'] ?? null);
        $family = $query['family'] ?? '';
        // An invalid/unknown slug takes the same fallback as a private Family.
        $family = is_string($family) && strlen($family) <= 255 ? strtolower(trim($family)) : '!';
        $page = CatalogBrowseQuery::fromValues(page: $query['page'] ?? null)->page;
        $isVariant = $query !== [];
        $response = response($this->storefrontResponseCache->get($search, $family === '' ? null : $family, $page, $isVariant))
            ->header('Content-Type', 'text/html; charset=UTF-8');

        if (! $isVariant) {
            return $response
                ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=60')
                ->header('CDN-Cache-Control', 'public, max-age=300, stale-while-revalidate=60')
                ->header('Surrogate-Control', 'max-age=300');
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
