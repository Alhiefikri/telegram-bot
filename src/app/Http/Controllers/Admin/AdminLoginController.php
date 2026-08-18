<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginAdminRequest;
use App\Models\User;
use App\Services\Admin\AdminLoginThrottle;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLoginController extends Controller
{
    public function __construct(
        private readonly AdminLoginThrottle $adminLoginThrottle,
    ) {}

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
        $ip = $request->ip() ?? 'unknown';
        $throttle = $this->adminLoginThrottle;

        $throttleStatus = $throttle->check($login, $ip);
        if ($throttleStatus->blocked) {
            throw $this->throttleException($throttleStatus->retryAfter);
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
                $throttleStatus = $throttle->recordFailure($login, $ip);
                if ($throttleStatus->blocked) {
                    $status = 'throttled';

                    throw $this->throttleException($throttleStatus->retryAfter);
                }

                throw ValidationException::withMessages([
                    'email' => 'Email atau password admin tidak cocok.',
                ]);
            }

            if (! $user->canAccessAdmin()) {
                $status = 'forbidden';
                $throttleStatus = $throttle->recordFailure($login, $ip);
                if ($throttleStatus->blocked) {
                    $status = 'throttled';

                    throw $this->throttleException($throttleStatus->retryAfter);
                }

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
            $throttle->clearIdentity($login, $ip);
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

    private function throttleException(int $seconds): ValidationException
    {
        return ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
