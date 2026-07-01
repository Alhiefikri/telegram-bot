<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminAccessAuthorizer implements AdminAccessAuthorizerInterface
{
    public function authorize(Request $request): User|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('admin.login'));
        }

        abort_unless($user instanceof User && $user->canAccessAdmin(), 403);

        return $user;
    }
}
