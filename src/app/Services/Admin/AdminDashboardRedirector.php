<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminDashboardRedirector
{
    public const TAB_STATS = 'stats';

    public const TAB_PRODUCTS = 'produk';

    public const TAB_SALES = 'penjualan';

    public const TAB_BROADCAST = 'broadcast';

    public const TAB_STORE_CHANNEL = 'telegram-channel';

    /**
     * @var array<string, string>
     */
    private const TAB_FALLBACK_TARGETS = [
        self::TAB_STATS => 'admin-section-stats',
        self::TAB_PRODUCTS => 'admin-section-products',
        self::TAB_SALES => 'admin-section-sales',
        self::TAB_BROADCAST => 'admin-section-broadcast',
        self::TAB_STORE_CHANNEL => 'admin-section-store-channel',
    ];

    /**
     * @param  array<string, string|int|null>  $query
     */
    public function dashboard(string $tab, ?string $target = null, array $query = []): RedirectResponse
    {
        $tab = array_key_exists($tab, self::TAB_FALLBACK_TARGETS) ? $tab : self::TAB_STATS;
        $target = $target ?: self::TAB_FALLBACK_TARGETS[$tab];
        $query = array_filter($query, fn ($value): bool => filled($value));

        return redirect()
            ->route('admin.dashboard', ['tab' => $tab] + $query)
            ->withFragment($target);
    }

    public function product(Product $product): RedirectResponse
    {
        return $this->dashboard(self::TAB_PRODUCTS, $this->productTarget($product));
    }

    public function products(): RedirectResponse
    {
        return $this->dashboard(self::TAB_PRODUCTS, self::TAB_FALLBACK_TARGETS[self::TAB_PRODUCTS]);
    }

    public function categoryManagement(?string $form = null, ?int $categoryId = null): RedirectResponse
    {
        return $this->dashboard(
            self::TAB_PRODUCTS,
            'admin-category-management',
            [
                'category_form' => $form,
                'category_id' => $categoryId,
            ],
        );
    }

    public function paymentSettings(): RedirectResponse
    {
        return $this->dashboard(self::TAB_STATS, 'admin-payment-settings');
    }

    public function supportSettings(): RedirectResponse
    {
        return $this->dashboard(self::TAB_STATS, 'admin-support-settings');
    }

    public function checkoutMaintenance(): RedirectResponse
    {
        return $this->dashboard(self::TAB_STATS, 'admin-checkout-maintenance');
    }

    public function broadcast(): RedirectResponse
    {
        return $this->dashboard(self::TAB_BROADCAST, self::TAB_FALLBACK_TARGETS[self::TAB_BROADCAST]);
    }

    public function storeChannel(): RedirectResponse
    {
        return $this->dashboard(self::TAB_STORE_CHANNEL, self::TAB_FALLBACK_TARGETS[self::TAB_STORE_CHANNEL]);
    }

    public function storeChannelTest(): RedirectResponse
    {
        return $this->dashboard(self::TAB_STORE_CHANNEL, 'admin-store-channel-test');
    }

    public function storeChannelFakeSalesProof(): RedirectResponse
    {
        return $this->dashboard(self::TAB_STORE_CHANNEL, 'admin-store-channel-fake-sales-proof');
    }

    public function salesProof(Order $order, ?Request $request = null): RedirectResponse
    {
        return $this->dashboard(self::TAB_SALES, 'sales-proof-'.$order->getKey(), $this->salesQueryFromPreviousUrl($request));
    }

    public function productTarget(Product $product): string
    {
        return 'product-'.$product->getKey();
    }

    /**
     * @return array<string, string>
     */
    private function salesQueryFromPreviousUrl(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $previousUrl = url()->previous();
        $query = [];
        parse_str((string) parse_url($previousUrl, PHP_URL_QUERY), $query);

        return collect($query)
            ->only(['sales_filter', 'sales_page'])
            ->map(fn ($value): string => is_scalar($value) ? (string) $value : '')
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }
}
