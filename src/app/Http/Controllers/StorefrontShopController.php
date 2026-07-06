<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontCatalogQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontShopController extends Controller
{
    public function __invoke(Request $request, StorefrontCatalogQuery $catalog): View
    {
        $search = $request->query('q');

        return view('storefront.shop', $catalog->getForShop(is_string($search) ? $search : ''));
    }
}
