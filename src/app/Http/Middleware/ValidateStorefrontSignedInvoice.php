<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ValidateStorefrontSignedInvoice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('testing') && ! $request->has('expires')) {
            throw new InvalidSignatureException;
        }

        if ($request->hasValidSignatureWhileIgnoring([], false)
            || $request->hasValidSignatureWhileIgnoring([], true)
            || $this->hasValidLegacyAppUrlSignature($request)) {
            return $next($request);
        }

        throw new InvalidSignatureException;
    }

    private function hasValidLegacyAppUrlSignature(Request $request): bool
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl === '') {
            return false;
        }

        $queryString = (string) $request->server->get('QUERY_STRING');
        $legacyUrl = $appUrl.'/'.ltrim($request->path(), '/');

        if ($queryString !== '') {
            $legacyUrl .= '?'.$queryString;
        }

        return URL::hasValidSignature(Request::create($legacyUrl, $request->method()), true);
    }
}
