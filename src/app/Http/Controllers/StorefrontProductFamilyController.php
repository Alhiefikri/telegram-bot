<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use App\Storefront\StorefrontFamilyPageBuilder;
use App\Storefront\StorefrontFunnelEvents;
use App\Storefront\StorefrontUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class StorefrontProductFamilyController extends Controller
{
    public function __construct(
        private readonly StorefrontFamilyPageBuilder $pages,
        private readonly StorefrontUrl $urls,
        private readonly StorefrontFunnelEvents $events,
    ) {}

    public function __invoke(Request $request, ProductFamily $productFamily): View
    {
        $viewData = $this->pages->viewData(
            $productFamily,
            $this->urls->familyReturnUrl($request, $productFamily->slug),
        );
        $this->events->familyOpened($productFamily);

        return view('storefront.family', $viewData);
    }
}
