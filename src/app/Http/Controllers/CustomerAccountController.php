<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerLoginLinkRequest;
use App\Http\Requests\CustomerOrderClaimRequest;
use App\Models\Order;
use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerAuthenticationService;
use App\Services\Customer\CustomerBalanceActivityCursorToken;
use App\Services\Customer\CustomerBalanceOverviewReader;
use App\Services\Customer\CustomerGoogleAuthenticationService;
use App\Services\Customer\CustomerOrderClaimService;
use App\Services\Customer\CustomerOwnedOrderQuery;
use App\Services\Customer\CustomerSessionAuthentication;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontSeo;
use App\Storefront\StorefrontShopOrderPresenter;
use App\Storefront\StorefrontShopPaymentStatusRefresh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerAccountController extends Controller
{
    public function landing(Request $request, StorefrontPresenter $storefront, StorefrontSeo $seo): Response|RedirectResponse
    {
        if ($request->user('customer')) {
            return redirect()->route('customer.orders.index');
        }

        $authEnabled = app(CustomerAuthAvailability::class)->enabled();
        $metadata = $seo->privatePage(
            'Akun Pelanggan | '.$storefront->brand(),
            'Masuk atau daftar dengan email untuk melihat pesanan dan saldo akun pelanggan.',
        );

        return $this->view($request, 'customer.account', [
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
            'authEnabled' => $authEnabled,
            'authView' => ! $authEnabled ? 'magic' : (in_array($request->query('view', session('customer_auth_view')), ['register', 'code', 'reset', 'magic', 'verification'], true)
                ? $request->query('view', session('customer_auth_view')) : 'login'),
            'telegramReady' => $authEnabled && app(CustomerTelegramAuthenticationService::class)->ready(),
            'googleReady' => $authEnabled && app(CustomerGoogleAuthenticationService::class)->ready(),
        ]);
    }

    public function requestLink(CustomerLoginLinkRequest $request, CustomerAuthenticationService $authentication): RedirectResponse
    {
        $authentication->requestLink((string) $request->validated('email'), $request);

        return redirect()->route('customer.account')->with('customer_login_sent', 'Jika email dapat digunakan, link login telah dikirim. Periksa inbox Anda.')
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function loginLink(Request $request, StorefrontPresenter $storefront, StorefrontSeo $seo): Response
    {
        $metadata = $seo->privatePage('Konfirmasi link login | '.$storefront->brand(), 'Konfirmasi akses akun pelanggan melalui link email.');

        return $this->view($request, 'customer.login-link', [
            'consumeUrl' => route('customer.login-link.consume'),
            'accountUrl' => route('customer.account'),
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'metadata' => $metadata,
        ]);
    }

    public function consume(Request $request, CustomerAuthenticationService $authentication): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i']]);

        if (! $authentication->consume((string) $validated['token'], $request)) {
            return redirect()->route('customer.account')->with('customer_login_error', 'Link login tidak valid atau sudah kedaluwarsa.')
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->route('customer.orders.index')
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function startTelegramLogin(Request $request, CustomerTelegramAuthenticationService $authentication): JsonResponse
    {
        return $this->telegramStart($request, $authentication, false);
    }

    public function startTelegramLink(Request $request, CustomerTelegramAuthenticationService $authentication): JsonResponse
    {
        return $this->telegramStart($request, $authentication, true);
    }

    public function telegramStatus(Request $request, CustomerTelegramAuthenticationService $authentication): JsonResponse
    {
        return response()->json(['status' => $authentication->status($request)])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function consumeTelegram(Request $request, CustomerTelegramAuthenticationService $authentication): JsonResponse
    {
        $consumed = $authentication->consume($request);

        return response()->json(['status' => $consumed ? 'complete' : 'invalid'], $consumed ? 200 : 422)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    private function telegramStart(Request $request, CustomerTelegramAuthenticationService $authentication, bool $link): JsonResponse
    {
        $claim = $authentication->start($request, $link);

        return response()->json($claim ?? ['status' => 'unavailable'], $claim === null ? 422 : 201)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(Request $request): RedirectResponse
    {
        app(CustomerSessionAuthentication::class)->logout($request);

        return redirect()->route('customer.account');
    }

    public function orders(Request $request, StorefrontPresenter $storefront, CustomerOwnedOrderQuery $ordersQuery, StorefrontSeo $seo): Response
    {
        $customer = $request->user('customer');
        $activeFilter = $ordersQuery->normalizeFilter($request->query('status'));
        $orders = $ordersQuery->paginateHistory($customer, $activeFilter);
        $filters = collect([
            ['key' => 'all', 'label' => 'Semua'],
            ['key' => 'waiting', 'label' => 'Menunggu'],
            ['key' => 'processing', 'label' => 'Diproses'],
            ['key' => 'completed', 'label' => 'Selesai'],
            ['key' => 'issue', 'label' => 'Masalah'],
        ])->map(fn (array $filter): array => $filter + [
            'active' => $filter['key'] === $activeFilter,
            'url' => route('customer.orders.index', ['status' => $filter['key']]),
        ])->all();

        $metadata = $seo->privatePage(
            'Pesanan Saya | '.$storefront->brand(),
            'Riwayat pesanan milik Anda. Halaman ini hanya tersedia setelah login.',
        );

        return $this->view($request, 'customer.orders', [
            'storefront' => $storefront, 'brand' => $storefront->brand(), 'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'), 'orders' => $orders, 'customer' => $customer,
            'filters' => $filters, 'activeFilter' => $activeFilter,
            'pageTitle' => $metadata->title, 'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl, 'metadata' => $metadata,
        ]);
    }

    public function balance(
        Request $request,
        CustomerBalanceOverviewReader $overview,
        StoreBalanceActivityService $storeActivity,
        CustomerUsdtActivityService $usdtActivity,
        CustomerBalanceActivityCursorToken $activityTokens,
        StorefrontPresenter $storefront,
        StorefrontSeo $seo,
    ): Response {
        $filters = $request->validate([
            'currency' => ['sometimes', 'string', 'in:IDR,USDT'],
            'direction' => ['sometimes', 'string', 'in:older,newer'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ]);
        $currency = $filters['currency'] ?? 'IDR';
        $direction = $filters['direction'] ?? null;
        $token = $filters['cursor'] ?? null;

        if (($direction === null) !== ($token === null)) {
            abort(404);
        }

        $customer = $request->user('customer');
        if ($currency === 'IDR') {
            $cursor = $token === null
                ? null
                : $activityTokens->decodeStoreBalanceToken($token, (int) $customer->getKey());
            if ($token !== null && $cursor === null) {
                abort(404);
            }
            $page = $storeActivity->pageForCustomer(
                $customer,
                $cursor,
                $direction === 'newer',
                StoreBalanceActivityService::WEB_PAGE_SIZE,
            );
            if ($page === null) {
                abort(404);
            }
            $olderToken = $page->hasOlder && $page->olderCursor !== null
                ? $activityTokens->storeBalanceToken((int) $customer->getKey(), $page->olderCursor)
                : null;
            $newerToken = $page->hasNewer && $page->newerCursor !== null
                ? $activityTokens->storeBalanceToken((int) $customer->getKey(), $page->newerCursor)
                : null;
            $activityRows = array_map(fn ($item): array => [
                'kind' => match ($item->kind) {
                    'top_up' => 'Top-up',
                    'compensation' => 'Kompensasi',
                    'adjustment' => 'Penyesuaian',
                    'adjustment_reversal' => 'Pembalikan penyesuaian',
                    'purchase' => 'Pembelian',
                    'refund' => 'Pengembalian',
                    default => 'Aktivitas saldo',
                },
                'state' => match ($item->state) {
                    'credited' => 'Dikreditkan',
                    'recovered' => 'Dipulihkan',
                    'compensated' => 'Dikompensasi',
                    'pending' => 'Menunggu',
                    'uncertain' => 'Perlu pemeriksaan',
                    'expired' => 'Kedaluwarsa',
                    'review' => 'Sedang ditinjau',
                    'failed' => 'Gagal',
                    'debited' => 'Didebit',
                    'refunded' => 'Dikembalikan',
                    default => 'Status tidak diketahui',
                },
                'amount' => $item->signedAmount === null
                    ? 'Nominal '.$this->formatIdr($item->amount)
                    : (($item->signedAmount < 0 ? '−' : '+').$this->formatIdr(abs($item->signedAmount))),
                'reference' => $item->reference,
                'occurred_at' => $item->occurredAt->format('d M Y H:i'),
            ], $page->items);
        } else {
            $cursor = $token === null
                ? null
                : $activityTokens->decodeUsdtToken($token, (int) $customer->getKey());
            if ($token !== null && $cursor === null) {
                abort(404);
            }
            if ($direction !== null && $cursor === null) {
                abort(404);
            }
            $page = $direction === null
                ? $usdtActivity->firstPage($customer, CustomerUsdtActivityService::DEFAULT_PAGE_SIZE)
                : ($direction === 'older'
                    ? $usdtActivity->olderPage($customer, $cursor, CustomerUsdtActivityService::DEFAULT_PAGE_SIZE)
                    : $usdtActivity->newerPage($customer, $cursor, CustomerUsdtActivityService::DEFAULT_PAGE_SIZE));
            if ($page === null) {
                abort(404);
            }
            $olderToken = $page->hasOlder && $page->olderCursor !== null
                ? $activityTokens->usdtToken((int) $customer->getKey(), $page->olderCursor)
                : null;
            $newerToken = $page->hasNewer && $page->newerCursor !== null
                ? $activityTokens->usdtToken((int) $customer->getKey(), $page->newerCursor)
                : null;
            $activityRows = array_map(fn ($item): array => [
                'kind' => $item->kind === 'top_up' ? 'Top-up' : match ($item->kind) {
                    'funding_credit' => 'Saldo masuk',
                    'purchase_debit' => 'Pembelian',
                    'refund_credit' => 'Pengembalian',
                    'reversal_debit' => 'Pembalikan',
                    default => 'Aktivitas saldo',
                },
                'state' => match ($item->state) {
                    'credited' => 'Dikreditkan',
                    'debited' => 'Didebit',
                    'refunded' => 'Dikembalikan',
                    'reversed' => 'Dibalik',
                    default => 'Menunggu',
                },
                'amount' => $item->signedMicro === null
                    ? 'Nominal '.UsdtMicroAmount::formatUnits($item->amountMicro).' USDT'
                    : (($item->signedMicro < 0 ? '−' : '+').UsdtMicroAmount::formatUnits(abs($item->signedMicro)).' USDT'),
                'reference' => $item->reference,
                'occurred_at' => $item->occurredAt->format('d M Y H:i'),
            ], $page->items);
        }

        $pageLinks = [
            'older' => $olderToken === null ? null : route('customer.balance.show', ['currency' => $currency, 'direction' => 'older', 'cursor' => $olderToken]),
            'newer' => $newerToken === null ? null : route('customer.balance.show', ['currency' => $currency, 'direction' => 'newer', 'cursor' => $newerToken]),
        ];
        $balanceOverview = $overview->forCustomer($customer);
        $idrBalanceDisplay = $balanceOverview->storeBalance->isAvailable()
            ? $this->formatIdr($balanceOverview->storeBalance->balance())
            : null;
        $usdtBalanceDisplay = $balanceOverview->usdtBalance->isAvailable()
            ? UsdtMicroAmount::formatUnits($balanceOverview->usdtBalance->balanceMicro()).' USDT'
            : null;
        $metadata = $seo->privatePage(
            'Saldo saya | '.$storefront->brand(),
            'Ringkasan dan riwayat saldo milik akun pelanggan yang sedang masuk.',
        );

        return $this->view($request, 'customer.balance', [
            'storefront' => $storefront,
            'brand' => $storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'customer' => $customer,
            'overview' => $balanceOverview,
            'idrBalanceDisplay' => $idrBalanceDisplay,
            'usdtBalanceDisplay' => $usdtBalanceDisplay,
            'currency' => $currency,
            'activityRows' => $activityRows,
            'pageLinks' => $pageLinks,
            'ordersUrl' => route('customer.orders.index'),
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
        ]);
    }

    public function show(Request $request, string $order, StorefrontShopOrderPresenter $presenter): Response
    {
        $ownedOrder = Order::query()->whereKey($order)->where('customer_id', $request->user('customer')->getKey())->firstOrFail();

        $data = $presenter->viewData($ownedOrder, canRevealCredentials: true, accessKey: null);
        $accountOrderUrl = route('customer.orders.show', $ownedOrder);
        $data['statusUrl'] = route('customer.orders.status', $ownedOrder);
        $data['redirectUrl'] = $accountOrderUrl;

        return $this->view($request, 'storefront.shop-order', $data);
    }

    public function status(Request $request, string $order, StorefrontShopPaymentStatusRefresh $refresh): JsonResponse
    {
        $ownedOrder = Order::query()->whereKey($order)->where('customer_id', $request->user('customer')->getKey())->firstOrFail();
        $result = $refresh->execute($ownedOrder);
        $payload = $result->payload();
        if ($result->ok) {
            $payload['redirect_url'] = route('customer.orders.show', $ownedOrder);
        }

        return response()
            ->json($payload, $result->statusCode())
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function claimForm(Request $request, StorefrontPresenter $storefront, StorefrontSeo $seo): Response
    {
        $metadata = $seo->privatePage(
            'Klaim Pesanan | '.$storefront->brand(),
            'Hubungkan pesanan guest ke akun pelanggan secara pribadi.',
        );

        return $this->view($request, 'customer.claim', [
            'storefront' => $storefront, 'brand' => $storefront->brand(), 'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'), 'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description, 'canonicalUrl' => $metadata->canonicalUrl,
            'metadata' => $metadata,
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

    private function formatIdr(int $amount): string
    {
        $digits = (string) $amount;
        $grouped = preg_replace('/\\B(?=(?:\\d{3})+(?!\\d))/', '.', $digits);

        return 'Rp '.($grouped ?? $digits);
    }

    private function view(Request $request, string $view, array $data): Response
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
