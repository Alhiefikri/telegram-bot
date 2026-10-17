<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

final class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        return config('app.trusted_proxies', []) ?: parent::proxies();
    }

    protected function getTrustedHeaderNames()
    {
        if (config('app.trusted_proxies', []) === []) {
            return parent::getTrustedHeaderNames();
        }

        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX;
    }
}
