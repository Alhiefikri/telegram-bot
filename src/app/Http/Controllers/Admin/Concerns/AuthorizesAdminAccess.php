<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait AuthorizesAdminAccess
{
    protected function redirectGuestToAdminLogin(Request $request): ?RedirectResponse
    {
        if ($request->user()) {
            return null;
        }

        return redirect()->guest(route('admin.login'));
    }

    protected function authorizeAdminAccess(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canAccessAdmin(), 403);

        return $user;
    }
}
