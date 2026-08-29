<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontCatalogPageBuilder;
use App\Storefront\StorefrontCatalogQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontShopController extends Controller
{
    public function __invoke(Request $request, StorefrontCatalogPageBuilder $catalogPages): View
    {
        $query = StorefrontCatalogQuery::fromRequest($request);

        return view('storefront.shop', $catalogPages->shopViewData($query));
    }
}
