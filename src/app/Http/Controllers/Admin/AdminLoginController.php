<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginAdminRequest;
use App\Models\User;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    public function store(LoginAdminRequest $request): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $querySnapshot = DogfoodTiming::querySnapshot();
        $passwordCheckMs = null;
        $status = 'ok';

        $credentials = $request->validated();
        $login = trim($credentials['email']);
        $throttleKey = Str::lower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ]);
        }

        $remember = $request->boolean('remember');

        try {
            $user = User::query()
                ->where('email', $login)
                ->orWhere('name', $login)
                ->first();

            $passwordStartedAt = DogfoodTiming::now();
            $passwordMatches = $user !== null && Hash::check($credentials['password'], $user->password);
            $passwordCheckMs = DogfoodTiming::elapsedMs($passwordStartedAt);

            if (! $user || ! $passwordMatches) {
                $status = 'bad_credentials';
                RateLimiter::hit($throttleKey, 60);

                throw ValidationException::withMessages([
                    'email' => 'Email atau password admin tidak cocok.',
                ]);
            }

            if (! $user->canAccessAdmin()) {
                $status = 'forbidden';
                RateLimiter::hit($throttleKey, 60);

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
            RateLimiter::clear($throttleKey);
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
