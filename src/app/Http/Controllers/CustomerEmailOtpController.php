<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerEmailOtpConsumeRequest;
use App\Http\Requests\CustomerLoginLinkRequest;
use App\Services\Customer\CustomerEmailOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class CustomerEmailOtpController extends Controller
{
    public function requestCode(CustomerLoginLinkRequest $request, CustomerEmailOtpService $authentication): RedirectResponse
    {
        $challenge = $authentication->requestCode($request->validated('email'), $request);
        if ($challenge !== null) {
            $request->session()->put('customer_otp_challenge', $challenge);
            $request->session()->put('customer_otp_email', Str::lower(trim($request->validated('email'))));
        } elseif (! $request->session()->has('customer_otp_challenge')) {
            $request->session()->put('customer_otp_challenge', bin2hex(random_bytes(32)));
            $request->session()->put('customer_otp_email', Str::lower(trim($request->validated('email'))));
        }

        return $this->redirect('customer.account')->with('customer_login_sent', 'Jika email dapat digunakan, kode telah dikirim. Periksa inbox Anda atau coba lagi nanti.');
    }

    public function consume(CustomerEmailOtpConsumeRequest $request, CustomerEmailOtpService $authentication): RedirectResponse
    {
        $input = $request->validated();
        if ($authentication->consume($input['email'], $input['challenge'], $input['code'], $request)) {
            return $this->redirect('customer.orders.index');
        }

        return $this->redirect('customer.account')->with('customer_login_error', 'Kode tidak dapat dikonfirmasi. Coba lagi atau minta kode baru.');
    }

    private function redirect(string $route): RedirectResponse
    {
        return redirect()->route($route)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
