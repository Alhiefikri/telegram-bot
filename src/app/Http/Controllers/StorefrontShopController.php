<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontCatalogPageBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontShopController extends Controller
{
    public function __invoke(Request $request, StorefrontCatalogPageBuilder $catalogPages): View
    {
        $search = $request->query('q');

        return view('storefront.shop', $catalogPages->shopViewData(is_string($search) ? $search : ''));
    }
}
