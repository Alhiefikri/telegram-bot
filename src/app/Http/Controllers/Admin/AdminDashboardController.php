<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        $panel = Filament::getPanel('admin');

        abort_unless($user->canAccessPanel($panel), 403);

        $ordersToProcess = Order::query()
            ->where('order_status', OrderStatus::Processing)
            ->count();

        $gmv = Order::query()
            ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed])
            ->sum('total_amount');

        return view('admin.dashboard', [
            'admin' => [
                'name' => $user->name ?: 'Admin LHIE',
                'role' => $user->role?->value ?? 'operator',
            ],
            'summary' => [
                ['label' => 'Saldo', 'value' => Money::idr(0), 'hint' => 'Siap withdraw', 'tone' => 'emerald'],
                ['label' => 'GMV', 'value' => Money::idr($gmv), 'hint' => 'Order aktif + selesai', 'tone' => 'blue'],
                ['label' => 'Produk', 'value' => Product::query()->where('is_active', true)->count(), 'hint' => 'Produk aktif', 'tone' => 'slate'],
                ['label' => 'Perlu diproses', 'value' => $ordersToProcess, 'hint' => 'Order dibayar', 'tone' => 'amber'],
            ],
            'storeStatus' => [
                'label' => 'Toko aktif',
                'hint' => $ordersToProcess > 0 ? 'Ada order perlu tindak lanjut' : 'Tidak ada antrean proses',
                'tone' => $ordersToProcess > 0 ? 'amber' : 'emerald',
            ],
            'actions' => [
                'shop' => url('/#products'),
                'share' => route('storefront.home'),
            ],
            'tabs' => [
                ['id' => 'stats', 'label' => 'Stats'],
                ['id' => 'produk', 'label' => 'Produk'],
                ['id' => 'penjualan', 'label' => 'Penjualan'],
                ['id' => 'tambah', 'label' => 'Tambah'],
                ['id' => 'withdraw', 'label' => 'Withdraw'],
                ['id' => 'riwayat', 'label' => 'Riwayat'],
                ['id' => 'affiliate', 'label' => 'Affiliate'],
            ],
        ]);
    }
}
