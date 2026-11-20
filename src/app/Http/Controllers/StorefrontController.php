<?php

namespace App\Http\Controllers;

use App\Catalog\CatalogBrowseQuery;
use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontController extends Controller
{
    private const CATALOG_FRAGMENT_HEADER = 'X-Storefront-Catalog';

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
        $family = $family === '' ? null : $family;
        $fragment = $request->header(self::CATALOG_FRAGMENT_HEADER) === '1';

        $response = response($fragment
            ? $this->storefrontResponseCache->fragment($search, $family, $page, $isVariant)
            : $this->storefrontResponseCache->get($search, $family, $page, $isVariant))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->setVary(self::CATALOG_FRAGMENT_HEADER, false);

        if ($fragment) {
            $response->header(self::CATALOG_FRAGMENT_HEADER, '1');
        }

        return $response;
    }
}
