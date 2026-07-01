<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

final class AdminDashboardData
{
    /**
     * @return array{
     *     admin: array{name: string, role: string},
     *     metrics: array<string, int>,
     *     navigation: array{activeTab: string, tabs: list<array{id: string, label: string}>},
     *     statsTab: array<string, mixed>,
     *     productsTab: array<string, mixed>,
     *     salesTab: array<string, mixed>
     * }
     */
    public function forAdmin(User $user, ?string $requestedTab = null): array
    {
        $metrics = $this->metrics();
        $statusCounts = $this->statusCounts();
        $storeLink = $this->storeLink();
        $canManageProducts = (bool) $user->role?->canMutateProducts();

        return [
            'admin' => $this->adminIdentity($user),
            'metrics' => $metrics,
            'navigation' => $this->navigation($requestedTab),
            'statsTab' => $this->statsTab($user, $metrics, $statusCounts, $storeLink),
            'productsTab' => $this->productsTab($canManageProducts),
            'salesTab' => $this->salesTab($statusCounts),
        ];
    }

    private function adminIdentity(User $user): array
    {
        return [
            'name' => $user->name ?: 'Admin LHIE',
            'role' => $user->role?->value ?? 'operator',
        ];
    }

    private function metrics(): array
    {
        $productCount = Product::query()->count();
        $activeProductCount = Product::query()->where('is_active', true)->count();
        $orderCount = Order::query()->count();
        $ordersToProcess = Order::query()->where('order_status', OrderStatus::Processing)->count();
        $gmvAmount = (int) Order::query()
            ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed])
            ->sum('total_amount');
        $readOnlyBalanceAmount = (int) Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->sum('amount');

        return [
            'read_only_balance_amount' => $readOnlyBalanceAmount,
            'gmv_amount' => $gmvAmount,
            'product_count' => $productCount,
            'active_product_count' => $activeProductCount,
            'order_count' => $orderCount,
            'orders_to_process' => $ordersToProcess,
        ];
    }

    private function navigation(?string $requestedTab): array
    {
        $tabs = [
            ['id' => 'stats', 'label' => 'Stats'],
            ['id' => 'produk', 'label' => 'Produk'],
            ['id' => 'penjualan', 'label' => 'Penjualan'],
            ['id' => 'broadcast', 'label' => 'Broadcast'],
            ['id' => 'withdraw', 'label' => 'Withdraw'],
            ['id' => 'riwayat', 'label' => 'Riwayat'],
            ['id' => 'affiliate', 'label' => 'Affiliate'],
        ];
        $tabIds = array_column($tabs, 'id');
        $requestedTab = (string) ($requestedTab ?: 'stats');

        return [
            'activeTab' => in_array($requestedTab, $tabIds, true) ? $requestedTab : 'stats',
            'tabs' => $tabs,
        ];
    }

    private function statsTab(User $user, array $metrics, array $statusCounts, array $storeLink): array
    {
        return [
            'balance' => $this->balance($metrics['read_only_balance_amount']),
            'storeLink' => $storeLink,
            'cards' => $this->statsCards($metrics),
            'summary' => $this->summaryCards($metrics),
            'storeStatus' => $this->storeStatus($metrics),
            'paymentControl' => $this->paymentControl($user),
            'statusCounts' => $statusCounts,
            'actionableStatusCounts' => $this->actionableStatusCounts($statusCounts),
            'earnings' => $this->earnings(),
            'actions' => [
                'shop' => url('/#products'),
                'share' => $storeLink['url'],
            ],
        ];
    }

    private function productsTab(bool $canManageProducts): array
    {
        return [
            'canManage' => $canManageProducts,
            'productStoreUrl' => route('admin.products.store'),
            'products' => $this->latestProducts($canManageProducts),
            'categoryOptions' => $this->categoryOptions(),
        ];
    }

    private function salesTab(array $statusCounts): array
    {
        return [
            'orders' => $this->latestOrders(),
            'filters' => $this->salesFilters($statusCounts),
            'statusCounts' => $statusCounts,
        ];
    }

    private function balance(int $readOnlyBalanceAmount): array
    {
        return [
            'label' => 'Saldo estimasi',
            'amount' => $readOnlyBalanceAmount,
            'amount_formatted' => Money::idr($readOnlyBalanceAmount),
            'source' => 'paid_payments',
        ];
    }

    private function storeLink(): array
    {
        $storeUrl = route('storefront.home');

        return [
            'label' => 'Link toko',
            'url' => $storeUrl,
            'display' => parse_url($storeUrl, PHP_URL_HOST) ?: $storeUrl,
        ];
    }

    private function statsCards(array $metrics): array
    {
        return [
            $this->metricCard('gmv', 'GMV', Money::idr($metrics['gmv_amount']), 'Order aktif + selesai', 'blue'),
            $this->metricCard('saldo', 'Saldo', Money::idr($metrics['read_only_balance_amount']), 'Estimasi pembayaran paid', 'emerald'),
            $this->metricCard('orders', 'Order', $metrics['order_count'], 'Total pesanan', 'amber'),
            $this->metricCard('active_products', 'Produk aktif', $metrics['active_product_count'], 'Tampil di toko', 'emerald'),
        ];
    }

    private function summaryCards(array $metrics): array
    {
        return [
            $this->metricCard('saldo', 'Saldo', Money::idr($metrics['read_only_balance_amount']), 'Estimasi pembayaran paid', 'emerald'),
            $this->metricCard('gmv', 'GMV', Money::idr($metrics['gmv_amount']), 'Order aktif + selesai', 'blue'),
            $this->metricCard('products', 'Produk', $metrics['product_count'], 'Total katalog', 'slate'),
            $this->metricCard('active_products', 'Produk aktif', $metrics['active_product_count'], 'Tampil di toko', 'emerald'),
            $this->metricCard('orders_to_process', 'Perlu diproses', $metrics['orders_to_process'], 'Order dibayar', 'amber'),
        ];
    }

    private function metricCard(string $key, string $label, int|string $value, string $hint, string $tone): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'hint' => $hint,
            'tone' => $tone,
            'card_class' => $this->toneCardClass($tone),
        ];
    }

    private function storeStatus(array $metrics): array
    {
        $activeProductCount = $metrics['active_product_count'];
        $ordersToProcess = $metrics['orders_to_process'];

        return [
            'label' => $activeProductCount > 0 ? 'Toko aktif' : 'Toko belum aktif',
            'hint' => $ordersToProcess > 0 ? 'Ada order perlu tindak lanjut' : 'Tidak ada antrean proses',
            'tone' => $ordersToProcess > 0 ? 'amber' : ($activeProductCount > 0 ? 'emerald' : 'slate'),
        ];
    }

    private function paymentControl(User $user): array
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: config('services.telegram.payment_mode', 'manual'));
        $mode = $mode === 'pakasir' ? 'pakasir' : 'manual';
        $isPakasirEnabled = $mode === 'pakasir';

        return [
            'mode' => $mode,
            'is_pakasir_enabled' => $isPakasirEnabled,
            'label' => $isPakasirEnabled ? 'Pakasir aktif' : 'Manual aktif',
            'hint' => $isPakasirEnabled
                ? 'Checkout memakai QRIS/hosted Pakasir dan auto-verifikasi.'
                : 'Checkout meminta bukti /payment dan menunggu approval admin.',
            'tone' => $isPakasirEnabled ? 'emerald' : 'amber',
            'source' => $settings ? 'dashboard_setting' : 'env_default',
            'can_manage' => (bool) $user->role?->canManagePaymentSettings(),
            'update_url' => route('admin.payment-mode.update'),
            'status_badge' => [
                'label' => $isPakasirEnabled ? 'AKTIF' : 'NONAKTIF',
                'background' => $isPakasirEnabled ? '#e8ffd4' : '#fce4f0',
                'dot' => $isPakasirEnabled ? '#7BD10A' : '#ef4444',
            ],
        ];
    }

    private function latestProducts(bool $includeManageableStockItems): array
    {
        $products = Product::query()
            ->with('category:id,name')
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'currency', 'stock', 'reserved_stock', 'is_active', 'created_at'])
            ->latest()
            ->limit(5)
            ->get();

        $productIds = $products->pluck('id')->all();
        $availableStockCounts = $includeManageableStockItems
            ? ProductStockItem::query()
                ->whereIn('product_id', $productIds)
                ->whereNull('order_item_id')
                ->selectRaw('product_id, count(*) as aggregate')
                ->groupBy('product_id')
                ->pluck('aggregate', 'product_id')
            : collect();

        return $products
            ->map(function (Product $product) use ($availableStockCounts, $includeManageableStockItems): array {
                $descriptionSections = $this->productDescriptionSections($product->description);
                $availableStockItemCount = (int) ($availableStockCounts[$product->id] ?? 0);
                $stockItems = $includeManageableStockItems
                    ? ProductStockItem::query()
                        ->where('product_id', $product->id)
                        ->whereNull('order_item_id')
                        ->oldest('id')
                        ->limit(25)
                        ->get()
                        ->map(fn (ProductStockItem $stockItem): array => [
                            'id' => $stockItem->id,
                            'secret' => $stockItem->secret,
                            'update_url' => route('admin.products.stock-items.update', ['product' => $product, 'stockItem' => $stockItem]),
                            'delete_url' => route('admin.products.stock-items.destroy', ['product' => $product, 'stockItem' => $stockItem]),
                        ])
                        ->all()
                    : [];
                $stockUrl = route('admin.products.stock.store', ['product' => $product]);
                $updateUrl = route('admin.products.update', ['product' => $product]);

                return [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'name' => $product->name,
                    'description' => $descriptionSections['description'],
                    'features' => $descriptionSections['features'],
                    'specifications' => $descriptionSections['specifications'],
                    'category' => $product->category?->name ?? 'Tanpa kategori',
                    'price_amount' => $product->price_amount,
                    'price' => Money::idr($product->price_amount),
                    'stock' => $product->stock,
                    'reserved_stock' => $product->reserved_stock,
                    'available_stock' => max(0, $product->stock - $product->reserved_stock),
                    'available_stock_item_count' => $availableStockItemCount,
                    'stock_items_truncated' => $availableStockItemCount > count($stockItems),
                    'stock_items' => $stockItems,
                    'is_active' => $product->is_active,
                    'status_label' => $product->is_active ? 'Aktif' : 'Pause',
                    'status_badge' => $this->productStatusBadge($product->is_active),
                    'visibility_label' => $product->is_active ? '● Tampil di toko' : '○ Tidak tampil',
                    'visibility_color' => $product->is_active ? '#7BD10A' : '#ef4444',
                    'visibility_action_label' => $product->is_active ? '⏸ Pause' : '▶ Play',
                    'update_url' => $updateUrl,
                    'delete_url' => route('admin.products.destroy', ['product' => $product]),
                    'stock_url' => $stockUrl,
                    'visibility_url' => route('admin.products.visibility.update', ['product' => $product]),
                    'share_url' => route('storefront.home').'#product-'.$product->id,
                    'created_at' => $product->created_at?->toDateTimeString(),
                    'stock_payload' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'stock' => $product->stock,
                        'stockUrl' => $stockUrl,
                    ],
                    'edit_payload' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'priceAmount' => $product->price_amount,
                        'categoryId' => $product->category_id,
                        'description' => $descriptionSections['description'],
                        'features' => $descriptionSections['features'],
                        'specifications' => $descriptionSections['specifications'],
                        'isActive' => $product->is_active,
                        'updateUrl' => $updateUrl,
                        'stockUrl' => $stockUrl,
                        'stock' => $product->stock,
                        'availableStockItemCount' => $availableStockItemCount,
                        'stockItemsTruncated' => $availableStockItemCount > count($stockItems),
                        'stockItems' => $stockItems,
                    ],
                ];
            })
            ->all();
    }

    private function productStatusBadge(bool $isActive): array
    {
        return [
            'background' => $isActive ? '#e8ffd4' : '#f1f5f9',
            'color' => $isActive ? '#166534' : '#64748b',
        ];
    }

    /**
     * @return array{description: string, features: string, specifications: string}
     */
    private function productDescriptionSections(?string $description): array
    {
        $description = trim((string) $description);
        $sections = preg_split("/\n{2,}/", $description) ?: [];
        $base = trim((string) ($sections[0] ?? ''));
        $features = '';
        $specifications = '';

        foreach (array_slice($sections, 1) as $section) {
            $section = trim((string) $section);

            if (str_starts_with($section, 'Fitur utama:')) {
                $features = $this->descriptionListBody($section, 'Fitur utama:');
            }

            if (str_starts_with($section, 'Paket/spesifikasi:')) {
                $specifications = $this->descriptionListBody($section, 'Paket/spesifikasi:');
            }
        }

        return [
            'description' => $base,
            'features' => $features,
            'specifications' => $specifications,
        ];
    }

    private function descriptionListBody(string $section, string $heading): string
    {
        $body = trim(substr($section, strlen($heading)));

        return collect(preg_split('/\R/u', $body) ?: [])
            ->map(fn (string $row): string => preg_replace('/^- /', '', trim($row)) ?? '')
            ->filter()
            ->implode("\n");
    }

    private function categoryOptions(): array
    {
        return Category::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all();
    }

    private function latestOrders(): array
    {
        return Order::query()
            ->with([
                'customer:id,first_name,username',
                'orderItems:id,order_id,product_name,quantity,subtotal',
                'payments' => fn ($query) => $query
                    ->where('status', PaymentStatus::Paid)
                    ->select(['id', 'order_id', 'amount', 'status']),
            ])
            ->select(['id', 'customer_id', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'created_at'])
            ->withCount('orderItems')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (Order $order): array {
                $paidAmount = (int) $order->payments->sum('amount');
                $orderStatusTone = $this->orderStatusTone($order->order_status);
                $paymentStatusTone = $this->paymentStatusTone($order->payment_status);

                return [
                    'id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'customer' => $this->customerLabel($order),
                    'product_name' => $this->orderProductName($order),
                    'filter_groups' => $this->orderFilterGroups($order->order_status),
                    'order_status' => $order->order_status->value,
                    'order_status_label' => $this->orderStatusLabel($order->order_status),
                    'order_status_tone' => $orderStatusTone,
                    'order_status_badge' => $this->toneBadgeStyle($orderStatusTone),
                    'payment_status' => $order->payment_status->value,
                    'payment_status_label' => $this->paymentStatusLabel($order->payment_status),
                    'payment_status_tone' => $paymentStatusTone,
                    'payment_status_badge' => $this->toneBadgeStyle($paymentStatusTone),
                    'price' => Money::idr($order->total_amount),
                    'earning' => Money::idr($paidAmount),
                    'earning_amount' => $paidAmount,
                    'item_count' => $order->order_items_count,
                    'created_at' => $order->created_at?->toDateTimeString(),
                    'created_at_label' => $order->created_at?->format('d M Y') ?? '-',
                ];
            })
            ->all();
    }

    private function statusCounts(): array
    {
        $counts = Order::query()
            ->selectRaw('order_status, count(*) as aggregate')
            ->groupBy('order_status')
            ->pluck('aggregate', 'order_status');

        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status): array => [
                $status->value => $this->statusCount($status, (int) ($counts[$status->value] ?? 0)),
            ])
            ->all();
    }

    private function statusCount(OrderStatus $status, int $count): array
    {
        $tone = $this->orderStatusTone($status);

        return [
            'label' => $this->orderStatusLabel($status),
            'count' => $count,
            'tone' => $tone,
            'card_class' => $this->toneCardClass($tone),
            'inline_style' => $tone === 'slate' ? 'background: #fff;' : null,
        ];
    }

    private function actionableStatusCounts(array $statusCounts): array
    {
        return collect([
            OrderStatus::PendingPayment,
            OrderStatus::Processing,
            OrderStatus::Cancelled,
            OrderStatus::Expired,
        ])
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $statusCounts[$status->value]])
            ->all();
    }

    private function salesFilters(array $statusCounts): array
    {
        $countFor = fn (OrderStatus ...$statuses): int => collect($statuses)
            ->sum(fn (OrderStatus $status): int => $statusCounts[$status->value]['count'] ?? 0);

        return [
            [
                'key' => 'all',
                'label' => 'Semua',
                'count' => $countFor(...OrderStatus::cases()),
            ],
            [
                'key' => 'completed',
                'label' => 'Selesai',
                'count' => $countFor(OrderStatus::Completed),
            ],
            [
                'key' => 'issue',
                'label' => 'Masalah',
                'count' => $countFor(OrderStatus::Cancelled, OrderStatus::Expired),
            ],
            [
                'key' => 'waiting',
                'label' => 'Menunggu',
                'count' => $countFor(OrderStatus::Draft, OrderStatus::PendingPayment),
            ],
            [
                'key' => 'processing',
                'label' => 'Diproses',
                'count' => $countFor(OrderStatus::Processing),
            ],
        ];
    }

    private function orderProductName(Order $order): string
    {
        $firstItem = $order->orderItems->first();

        if (! $firstItem) {
            return 'Produk';
        }

        $remainingItems = max(0, $order->order_items_count - 1);

        return $remainingItems > 0
            ? "{$firstItem->product_name} + {$remainingItems} produk"
            : $firstItem->product_name;
    }

    private function orderFilterGroups(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Completed => ['all', 'completed'],
            OrderStatus::Cancelled, OrderStatus::Expired => ['all', 'issue'],
            OrderStatus::Draft, OrderStatus::PendingPayment => ['all', 'waiting'],
            OrderStatus::Processing => ['all', 'processing'],
        };
    }

    private function earnings(): array
    {
        $start = now()->startOfDay()->subDays(6);
        $payments = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->get(['amount', 'paid_at']);

        $amountsByDate = $payments
            ->groupBy(fn (Payment $payment): string => $payment->paid_at->toDateString())
            ->map(fn ($payments): int => (int) $payments->sum('amount'));
        $maxAmount = max(1, (int) $amountsByDate->max());

        return collect(range(0, 6))
            ->map(function (int $dayOffset) use ($start, $amountsByDate, $maxAmount): array {
                $date = (clone $start)->addDays($dayOffset);
                $amount = (int) ($amountsByDate[$date->toDateString()] ?? 0);

                return [
                    'date' => $date->toDateString(),
                    'label' => Carbon::parse($date)->format('d M'),
                    'amount' => $amount,
                    'amount_formatted' => Money::idr($amount),
                    'bar_percent' => $amount > 0 ? max(8, (int) round(($amount / $maxAmount) * 100)) : 0,
                ];
            })
            ->all();
    }

    private function customerLabel(Order $order): string
    {
        if (! $order->customer) {
            return 'Pelanggan';
        }

        if ($order->customer->first_name && $order->customer->username) {
            return "{$order->customer->first_name} (@{$order->customer->username})";
        }

        return $order->customer->first_name ?: ($order->customer->username ? "@{$order->customer->username}" : 'Pelanggan');
    }

    private function orderStatusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'Draft',
            OrderStatus::PendingPayment => 'Menunggu bayar',
            OrderStatus::Processing => 'Diproses',
            OrderStatus::Completed => 'Selesai',
            OrderStatus::Cancelled => 'Dibatalkan',
            OrderStatus::Expired => 'Expired',
        };
    }

    private function paymentStatusLabel(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'Pending',
            PaymentStatus::Paid => 'Paid',
            PaymentStatus::Failed => 'Failed',
            PaymentStatus::Expired => 'Expired',
            PaymentStatus::Refunded => 'Refunded',
        };
    }

    private function paymentStatusTone(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Paid => 'emerald',
            PaymentStatus::Failed, PaymentStatus::Expired => 'rose',
            PaymentStatus::Pending => 'amber',
            PaymentStatus::Refunded => 'slate',
        };
    }

    private function orderStatusTone(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Processing => 'amber',
            OrderStatus::Completed => 'emerald',
            OrderStatus::Cancelled, OrderStatus::Expired => 'rose',
            default => 'slate',
        };
    }

    private function toneCardClass(string $tone): string
    {
        return match ($tone) {
            'emerald' => 'stat-card-green',
            'blue' => 'stat-card-cyan',
            'amber' => 'stat-card-yellow',
            'rose' => 'stat-card-pink',
            default => 'stat-card-purple',
        };
    }

    private function toneBadgeStyle(string $tone): array
    {
        return match ($tone) {
            'emerald' => ['background' => '#e8ffd4', 'color' => '#166534'],
            'amber' => ['background' => '#fef9c3', 'color' => '#92400e'],
            'blue' => ['background' => '#d4f6fb', 'color' => '#0e7490'],
            'rose' => ['background' => '#fecaca', 'color' => '#dc2626'],
            default => ['background' => '#f1f5f9', 'color' => '#64748b'],
        };
    }
}
