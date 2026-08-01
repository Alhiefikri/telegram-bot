<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'));
        $canonicalHost = strtolower((string) ($canonical['host'] ?? ''));
        if ($canonicalHost === '' || strtolower($request->getHost()) === $canonicalHost) {
            return $next($request);
        }

        $alternateHost = str_starts_with($canonicalHost, 'www.')
            ? substr($canonicalHost, 4)
            : 'www.'.$canonicalHost;
        if (strtolower($request->getHost()) !== $alternateHost) {
            return $next($request);
        }

        if (! $request->isMethodSafe()) {
            return response('Use the canonical host for this request.', 421);
        }

        $scheme = (string) ($canonical['scheme'] ?? $request->getScheme());
        $port = isset($canonical['port']) ? ':'.(int) $canonical['port'] : '';

        return redirect()->away($scheme.'://'.$canonicalHost.$port.$request->getRequestUri(), 308);
    }
}
