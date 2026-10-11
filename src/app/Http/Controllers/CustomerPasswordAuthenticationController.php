<?php

namespace App\Http\Controllers;

use App\Enums\CustomerIdentityProvider;
use App\Http\Requests\CustomerLoginLinkRequest;
use App\Http\Requests\CustomerPasswordEnrollmentRequest;
use App\Http\Requests\CustomerPasswordLoginRequest;
use App\Http\Requests\CustomerPasswordResetRequest;
use App\Http\Requests\CustomerRegistrationRequest;
use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerGoogleAuthenticationService;
use App\Services\Customer\CustomerPasswordAuthenticationService;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontSeo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerPasswordAuthenticationController extends Controller
{
    public function register(CustomerRegistrationRequest $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        $authentication->register($request->validated('email'), $request->validated('password'), $request->validated('name'));

        return $this->sent()->with('customer_auth_view', 'verification');
    }

    public function enrollTelegramEmail(CustomerLoginLinkRequest $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        $authentication->enrollTelegramEmail($request->validated('email'), $request);

        return $this->sent();
    }

    public function login(CustomerPasswordLoginRequest $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        return $authentication->login($request->validated('email'), $request->validated('password'), $request)
            ? $this->redirect('customer.orders.index')
            : $this->failed();
    }

    public function verification(Request $request, StorefrontPresenter $storefront, StorefrontSeo $seo): Response
    {
        return $this->confirmation($storefront, $seo, false);
    }

    public function verify(Request $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        $token = $request->input('token');

        return is_string($token) && $authentication->verify($token, $request)
            ? $this->redirect('customer.orders.index')
            : $this->failed()->with('customer_login_error', 'Link verifikasi tidak valid atau kedaluwarsa. Minta instruksi baru, atau masuk jika akun sudah tersedia.');
    }

    public function requestReset(CustomerLoginLinkRequest $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        $authentication->requestReset($request->validated('email'));

        return $this->sent()->with('customer_auth_view', 'reset');
    }

    public function resetForm(Request $request, StorefrontPresenter $storefront, StorefrontSeo $seo): Response
    {
        return $this->confirmation($storefront, $seo, true);
    }

    public function reset(CustomerPasswordResetRequest $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        if (! $authentication->reset((string) $request->validated('token'), $request->validated('password'), $request)) {
            return $this->failed()->with('customer_auth_view', 'reset')->with('customer_login_error', 'Link pemulihan tidak valid atau kedaluwarsa. Minta instruksi baru.');
        }

        return $this->redirect('customer.account')->with('customer_login_sent', 'Password diperbarui. Silakan masuk kembali.');
    }

    public function security(Request $request, StorefrontPresenter $storefront, StorefrontSeo $seo): Response
    {
        $metadata = $seo->privatePage('Keamanan akun | '.$storefront->brand(), 'Lengkapi password akun pelanggan.');
        $authEnabled = app(CustomerAuthAvailability::class)->enabled();

        return response()->view('customer.security', [
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'metadata' => $metadata,
            'authEnabled' => $authEnabled,
            'customer' => $request->user('customer'),
            'googleReady' => $authEnabled && app(CustomerGoogleAuthenticationService::class)->ready(),
            'googleLinked' => $request->user('customer')->identities()->where('provider', CustomerIdentityProvider::Google->value)->exists(),
            'telegramReady' => $authEnabled && app(CustomerTelegramAuthenticationService::class)->ready(),
            'telegramAuthenticated' => $request->session()->get('customer_telegram_authenticated') === true,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function setPassword(CustomerPasswordEnrollmentRequest $request, CustomerPasswordAuthenticationService $authentication): RedirectResponse
    {
        return $authentication->setPassword($request->user('customer'), $request->validated('password'), $request)
            ? $this->redirect('customer.security')->with('customer_login_sent', 'Password akun telah disimpan.')
            : $this->redirect('customer.security')->with('customer_login_error', 'Password tidak dapat disimpan. Masuk kembali melalui link email untuk konfirmasi baru, atau gunakan pemulihan password.');
    }

    private function confirmation(StorefrontPresenter $storefront, StorefrontSeo $seo, bool $reset): Response
    {
        $metadata = $seo->privatePage(
            ($reset ? 'Atur ulang password' : 'Verifikasi email').' | '.$storefront->brand(),
            'Konfirmasi akses akun pelanggan.',
        );

        return response()->view('customer.auth-confirmation', [
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'metadata' => $metadata,
            'reset' => $reset,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    private function sent(): RedirectResponse
    {
        return $this->redirect('customer.account')->with('customer_login_sent', 'Jika email dapat digunakan, instruksi telah dikirim. Periksa inbox Anda, atau masuk dan pulihkan akses akun yang sudah ada.');
    }

    private function failed(): RedirectResponse
    {
        return $this->redirect('customer.account')->with('customer_login_error', 'Akses tidak dapat dikonfirmasi. Coba masuk atau minta instruksi baru.');
    }

    private function redirect(string $route): RedirectResponse
    {
        return redirect()->route($route)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
