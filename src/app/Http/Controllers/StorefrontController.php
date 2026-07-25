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

        return response($this->storefrontResponseCache->get($search))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
