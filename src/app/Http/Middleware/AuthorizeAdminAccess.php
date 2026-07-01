<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccessAuthorizerInterface;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeAdminAccess
{
    public function __construct(private readonly AdminAccessAuthorizerInterface $adminAccess)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $authorized = $this->adminAccess->authorize($request);

        if ($authorized instanceof RedirectResponse) {
            return $authorized;
        }

        $request->attributes->set('adminUser', $authorized);

        return $next($request);
    }
}
