<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use App\Storefront\StorefrontFamilyPageBuilder;
use App\Storefront\StorefrontFamilySlugResolver;
use App\Storefront\StorefrontFunnelEvents;
use App\Storefront\StorefrontUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class StorefrontProductFamilyController extends Controller
{
    public function __construct(
        private readonly StorefrontFamilyPageBuilder $pages,
        private readonly StorefrontFamilySlugResolver $families,
        private readonly StorefrontUrl $urls,
        private readonly StorefrontFunnelEvents $events,
    ) {}

    public function __invoke(Request $request, ProductFamily $productFamily): View
    {
        $family = $this->families->current($productFamily->slug);
        abort_unless($family instanceof ProductFamily, 404);

        $viewData = $this->pages->viewData(
            $family,
            $this->urls->familyReturnUrl($request, $family->slug),
        );
        $this->events->familyOpened($family);

        return view('storefront.family', $viewData);
    }
}
