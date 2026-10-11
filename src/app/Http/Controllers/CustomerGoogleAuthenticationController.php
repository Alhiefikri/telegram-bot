<?php

namespace App\Http\Controllers;

use App\Services\Customer\CustomerGoogleAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CustomerGoogleAuthenticationController extends Controller
{
    public function redirect(Request $request, CustomerGoogleAuthenticationService $authentication): RedirectResponse
    {
        $operation = $request->query('operation', 'login');
        if (! in_array($operation, ['login', 'register'], true)) {
            return $this->failure('customer.account', 'invalid');
        }

        $url = $authentication->start($request, $operation);

        return $url === null
            ? $this->failure('customer.account', 'unavailable')
            : redirect()->away($url)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, CustomerGoogleAuthenticationService $authentication): RedirectResponse
    {
        $outcome = $authentication->callback($request);
        // StartSession records GET history after controller dispatch; never retain callback capabilities.
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');
        $request->server->set('REQUEST_URI', '/auth/google/callback');
        if ($outcome === 'authenticated') {
            return $this->to('customer.orders.index');
        }
        if ($outcome === 'linked') {
            return $this->to('customer.security')->with('customer_login_sent', 'Google telah dihubungkan ke akun ini.');
        }

        return $this->failure($request->user('customer') ? 'customer.security' : 'customer.account', $outcome);
    }

    public function link(Request $request, CustomerGoogleAuthenticationService $authentication): RedirectResponse
    {
        $url = $authentication->start($request, 'link');

        return $url === null
            ? $this->failure('customer.security', 'invalid')
            : redirect()->away($url)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function unlink(Request $request, CustomerGoogleAuthenticationService $authentication): RedirectResponse
    {
        $outcome = $authentication->unlink($request);

        return $outcome === 'unlinked'
            ? $this->to('customer.security')->with('customer_login_sent', 'Google telah dilepas. Metode masuk lain tetap tersedia.')
            : $this->failure('customer.security', $outcome);
    }

    private function failure(string $route, string $outcome): RedirectResponse
    {
        $message = match ($outcome) {
            'collision' => 'Google belum dihubungkan. Masuk dengan metode akun yang sudah ada, lalu hubungkan Google dari Keamanan akun dalam lima menit setelah masuk.',
            'conflict' => 'Google tidak dapat dihubungkan karena identitas sudah digunakan. Tidak ada akun atau pesanan yang dipindahkan.',
            'last_method' => 'Google tidak dapat dilepas sebelum metode masuk lain yang terverifikasi tersedia.',
            'unavailable' => 'Google belum dapat digunakan. Coba lagi nanti atau gunakan metode email.',
            default => 'Akses Google tidak dapat dikonfirmasi. Mulai lagi; untuk menghubungkan atau melepas Google, masuk kembali dalam lima menit terakhir.',
        };

        return $this->to($route)->with('customer_login_error', $message);
    }

    private function to(string $route): RedirectResponse
    {
        return redirect()->route($route)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
