<?php

namespace App\Http\Controllers;

use App\Storefront\StorefrontFamilySlugResolver;
use App\Storefront\StorefrontUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StorefrontLegacyProductFamilyRedirectController
{
    public function __construct(
        private readonly StorefrontFamilySlugResolver $families,
        private readonly StorefrontUrl $urls,
    ) {}

    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        $family = $this->families->resolvePublic($slug);
        abort_unless($family !== null, 404);

        $canonical = $this->urls->familyUrl($family->slug);
        $returnUrl = $this->urls->familyReturnUrl($request, $family->slug);
        $location = $returnUrl === $canonical
            ? $canonical
            : $this->urls->familyUrl($family->slug, $returnUrl);

        return redirect()->to($location, 301);
    }
}
