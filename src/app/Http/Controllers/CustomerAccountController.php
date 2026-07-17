<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Requests\CustomerLoginLinkRequest;
use App\Http\Requests\CustomerOrderClaimRequest;
use App\Models\Order;
use App\Services\Customer\CustomerAuthenticationService;
use App\Services\Customer\CustomerOrderClaimService;
use App\Services\Customer\CustomerOwnedOrderQuery;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontShopOrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerAccountController extends Controller
{
    public function landing(Request $request, StorefrontPresenter $storefront): Response|RedirectResponse
    {
        if ($request->user('customer')) {
            return redirect()->route('customer.orders.index');
        }

        return $this->view($request, 'customer.account', [
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'pageTitle' => 'Akun Pelanggan | '.$storefront->brand(),
            'pageDescription' => 'Masuk ke akun pelanggan menggunakan magic link email.',
            'canonicalUrl' => route('customer.account'),
        ]);
    }

    public function requestLink(CustomerLoginLinkRequest $request, CustomerAuthenticationService $authentication): RedirectResponse
    {
        $authentication->requestLink((string) $request->validated('email'), $request);

        return redirect()->route('customer.account')->with('customer_login_sent', 'Jika email dapat digunakan, link login telah dikirim. Periksa inbox Anda.')
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function consume(Request $request, string $token, CustomerAuthenticationService $authentication): RedirectResponse
    {
        if (! $authentication->consume($token, $request)) {
            return redirect()->route('customer.account')->with('customer_login_error', 'Link login tidak valid atau sudah kedaluwarsa.')
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->route('customer.orders.index')
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(Request $request): RedirectResponse
    {
        auth('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.account');
    }

    public function orders(Request $request, StorefrontPresenter $storefront, CustomerOwnedOrderQuery $ordersQuery): Response
    {
        $customer = $request->user('customer');
        $orders = $ordersQuery->paginatePaid($customer);

        return $this->view($request, 'customer.orders', [
            'storefront' => $storefront, 'brand' => $storefront->brand(), 'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'), 'orders' => $orders, 'customer' => $customer,
            'pageTitle' => 'Pesanan Saya | '.$storefront->brand(), 'pageDescription' => 'Pesanan paid milik Anda.',
            'canonicalUrl' => route('customer.orders.index'),
        ]);
    }

    public function show(Request $request, string $order, StorefrontShopOrderPresenter $presenter, StorefrontPresenter $storefront): Response
    {
        $ownedOrder = Order::query()->whereKey($order)->where('customer_id', $request->user('customer')->getKey())->firstOrFail();
        abort_unless($ownedOrder->payment_status === PaymentStatus::Paid, 403);

        $data = $presenter->viewData($ownedOrder, canRevealCredentials: true, accessKey: null);
        $accountOrderUrl = route('customer.orders.show', $ownedOrder);
        $data['canonicalUrl'] = $accountOrderUrl;
        $data['statusUrl'] = $accountOrderUrl;
        $data['redirectUrl'] = $accountOrderUrl;

        return $this->view($request, 'storefront.shop-order', $data);
    }

    public function claimForm(Request $request, StorefrontPresenter $storefront): Response
    {
        return $this->view($request, 'customer.claim', [
            'storefront' => $storefront, 'brand' => $storefront->brand(), 'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'), 'pageTitle' => 'Klaim Pesanan | '.$storefront->brand(),
            'pageDescription' => 'Hubungkan pesanan guest ke akun pelanggan.', 'canonicalUrl' => route('customer.claim.create'),
        ]);
    }

    public function claim(CustomerOrderClaimRequest $request, CustomerOrderClaimService $claims): RedirectResponse
    {
        $order = $claims->claim($request->user('customer'), (string) $request->validated('invoice_number'), (string) $request->validated('access_key'));

        if (! $order) {
            return back()->withInput($request->except('access_key'))->with('customer_claim_error', 'Nomor invoice atau recovery key tidak cocok.');
        }

        return redirect()->route('customer.orders.show', $order);
    }

    private function view(Request $request, string $view, array $data): Response
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
