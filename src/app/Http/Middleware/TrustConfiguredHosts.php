<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

final class TrustConfiguredHosts extends TrustHosts
{
    public function handle(Request $request, $next)
    {
        if (config('app.trusted_hosts', []) === []) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    public function hosts()
    {
        return [
            ...RedirectToCanonicalHost::trustedHosts(
                config('app.trusted_hosts', []),
                (string) config('app.url'),
            ),
            $this->allSubdomainsOfApplicationUrl(),
        ];
    }
}
