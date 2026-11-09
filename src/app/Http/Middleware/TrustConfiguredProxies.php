<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

final class TrustConfiguredProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // Do not inherit host-based auto-trust or dynamic REMOTE_ADDR aliases.
        $proxies = array_filter(config('app.trusted_proxies', []), static function (string $proxy): bool {
            $parts = explode('/', $proxy);
            $ip = $parts[0];
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || count($parts) > 2) {
                return false;
            }

            if (count($parts) === 1) {
                return true;
            }

            $maximum = str_contains($ip, ':') ? 128 : 32;

            return ctype_digit($parts[1]) && (int) $parts[1] > 0 && (int) $parts[1] <= $maximum;
        });

        $request->setTrustedProxies(array_values($proxies), $this->getTrustedHeaderNames());
    }

    protected function getTrustedHeaderNames()
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX;
    }
}
