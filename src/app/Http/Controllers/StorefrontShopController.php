<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontResponseCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontShopController extends Controller
{
    public function __construct(private readonly StorefrontResponseCache $storefrontResponseCache) {}

    public function __invoke(Request $request): Response
    {
        $search = $request->query('q');

        return response($this->storefrontResponseCache->get(is_string($search) ? $search : ''))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
