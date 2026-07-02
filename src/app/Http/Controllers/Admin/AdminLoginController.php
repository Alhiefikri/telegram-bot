<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return view('admin.login');
        }

        abort_unless($user->canAccessAdmin(), 403);

        return redirect()->route('admin.dashboard');
    }

    public function store(Request $request): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $querySnapshot = DogfoodTiming::querySnapshot();
        $passwordCheckMs = null;
        $status = 'ok';

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Email admin wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password admin wajib diisi.',
        ]);

        $remember = $request->boolean('remember');

        try {
            $user = User::query()
                ->where('email', $credentials['email'])
                ->first();

            $passwordStartedAt = DogfoodTiming::now();
            $passwordMatches = $user !== null && Hash::check($credentials['password'], $user->password);
            $passwordCheckMs = DogfoodTiming::elapsedMs($passwordStartedAt);

            if (! $user || ! $passwordMatches) {
                $status = 'bad_credentials';

                throw ValidationException::withMessages([
                    'email' => 'Email atau password admin tidak cocok.',
                ]);
            }

            if (! $user->canAccessAdmin()) {
                $status = 'forbidden';

                throw ValidationException::withMessages([
                    'email' => 'Akun ini tidak punya akses admin.',
                ]);
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill([
                    'password' => Hash::make($credentials['password']),
                ])->save();
            }

            Auth::login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'));
        } finally {
            DogfoodTiming::log('dogfood.admin_login.store', DogfoodTiming::queryDelta($querySnapshot) + [
                'status' => $status,
                'password_check_ms' => $passwordCheckMs,
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ]);
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
