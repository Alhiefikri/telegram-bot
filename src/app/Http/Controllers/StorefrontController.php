<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontCatalogQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(private readonly StorefrontCatalogQuery $catalogQuery) {}

    public function __invoke(Request $request): View
    {
        $search = $request->query('q');

        return view('storefront', $this->catalogQuery->get(is_string($search) ? $search : ''));
    }
}
