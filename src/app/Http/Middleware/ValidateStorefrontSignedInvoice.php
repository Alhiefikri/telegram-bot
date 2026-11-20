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
        if (! $this->isValid($request)) {
            throw new InvalidSignatureException;
        }

        return $next($request);
    }

    public function isValid(Request $request): bool
    {
        if (! app()->environment('testing') && ! $request->has('expires')) {
            return false;
        }

        return $request->hasValidSignatureWhileIgnoring([], false)
            || $request->hasValidSignatureWhileIgnoring([], true)
            || $this->hasValidLegacyAppUrlSignature($request);
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
