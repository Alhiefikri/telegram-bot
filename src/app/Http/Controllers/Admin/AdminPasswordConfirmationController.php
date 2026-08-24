<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminPasswordConfirmationController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessAdmin()) {
            return redirect()->route('admin.login');
        }

        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessAdmin()) {
            return redirect()->route('admin.login');
        }

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Password konfirmasi tidak cocok.',
            ]);
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('admin.dashboard'));
    }
}
