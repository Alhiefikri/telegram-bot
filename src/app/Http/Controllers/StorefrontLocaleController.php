<?php

namespace App\Http\Controllers;

use App\Catalog\CatalogBrowseQuery;
use App\Http\Middleware\ValidateStorefrontSignedInvoice;
use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontLocale;
use App\Storefront\StorefrontLocaleReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;

class StorefrontLocaleController extends Controller
{
    public function catalog(Request $request, string $locale): RedirectResponse
    {
        $target = $this->localTarget($request, $request->query('return', '/'));
        abort_unless($target->getPathInfo() === '/', 400);

        $family = $target->query('family');
        $query = array_filter([
            'family' => is_string($family) && preg_match('/\A[a-z0-9-]{1,255}\z/i', $family) ? strtolower($family) : null,
            'q' => StorefrontCatalogSearch::fromRequestQuery($target->query('q')),
            'page' => CatalogBrowseQuery::fromValues(page: $target->query('page'))->page,
        ], fn ($value, $key) => $key === 'page' ? $value > 1 : filled($value), ARRAY_FILTER_USE_BOTH);

        return $this->redirect($request, $locale, route('storefront.home', $query, absolute: false));
    }

    public function update(Request $request, ValidateStorefrontSignedInvoice $invoiceSignature, StorefrontLocaleReturn $returnPaths): RedirectResponse
    {
        $body = $request->request->all();
        $return = $body['return'] ?? null;
        $target = $this->localTarget($request, $return);

        // Only match route definitions: no model binding or dispatch to the target.
        foreach (StorefrontLocaleReturn::POST_ROUTES as $name) {
            if (Route::getRoutes()->getByName($name)?->matches($target)) {
                abort_if($name === 'storefront.shop.orders.show' && ! $invoiceSignature->isValid($target), 403);

                // Never reconstruct a signed path/query or extend its expiration.
                return $this->redirect($request, $body['locale'] ?? null, $name === 'storefront.shop.orders.show' ? $return : $returnPaths->path($target, $name));
            }
        }

        abort(400);
    }

    private function localTarget(Request $request, mixed $return): Request
    {
        abort_unless(is_string($return) && strlen($return) <= 4096
            && ! preg_match('/[\x00-\x20\x7f#]/', $return), 400);

        // Reject browser URL rewriting (including authority, backslash and dot segments).
        $path = explode('?', $return, 2)[0];
        $decodedPath = rawurldecode($path);
        abort_unless(str_starts_with($path, '/') && ! str_contains($path, '//')
            && ! preg_match('/%(?:2f|5c|25)/i', $path)
            && ! str_contains($decodedPath, '\\')
            && ! preg_match('~[\x00-\x20\x7f?#]|(?:^|/)\.{1,2}(?:/|$)~', $decodedPath), 400);

        return Request::create($request->getSchemeAndHttpHost().$return, 'GET');
    }

    private function redirect(Request $request, mixed $value, string $return): RedirectResponse
    {
        $locale = StorefrontLocale::preference($value);
        abort_if($locale === null, 400);

        $response = new RedirectResponse($return, 303, ['Referrer-Policy' => 'no-referrer']);
        $response->headers->setCookie(new Cookie(StorefrontLocale::COOKIE, $locale, now()->addYear(), '/', null, $request->isSecure()));

        return $response;
    }
}
