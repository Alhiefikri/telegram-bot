<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeStoreBalanceCompensation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('adminUser');
        abort_unless($user instanceof User && $user->role?->canCompensateStoreBalance() === true, 403);

        return $next($request);
    }
}
