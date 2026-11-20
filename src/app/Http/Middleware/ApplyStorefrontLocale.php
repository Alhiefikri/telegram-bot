<?php

namespace App\Http\Middleware;

use App\Storefront\StorefrontLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyStorefrontLocale
{
    public function handle(Request $request, Closure $next, ?string $visibility = null): Response
    {
        $locale = StorefrontLocale::preference($request->cookie(StorefrontLocale::COOKIE)) ?? StorefrontLocale::code();
        $request->attributes->set(StorefrontLocale::class, $locale);
        $translator = app('translator');
        $previous = $translator->getLocale();
        $translator->setLocale($locale);

        try {
            $response = $next($request);
        } finally {
            $translator->setLocale($previous);
            $request->attributes->remove(StorefrontLocale::class);
        }

        // CDN rules are not qualified for locale negotiation, even for guests.
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($visibility === 'private') {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
