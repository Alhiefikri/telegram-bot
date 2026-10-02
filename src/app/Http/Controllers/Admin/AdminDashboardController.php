<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\AdminDashboardData;
use App\Services\Admin\AdminDashboardProductsData;
use App\Services\Admin\AdminProductDashboardState;
use App\Services\Admin\StorefrontFunnelReport;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardData $dashboardData, AdminProductDashboardState $dashboardState, StorefrontFunnelReport $funnelReport, AdminDashboardProductsData $productsData): View|RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $requestedTab = (string) $request->query('tab', 'stats');
        if ($requestedTab === StorefrontFunnelReport::TAB) {
            abort_unless($funnelReport->canView($user), 403);
        }
        if ($requestedTab === StoreBalanceOwnerCompensationService::TAB) {
            abort_unless($user?->role?->canCompensateStoreBalance() === true, 403);
        }
        if ($requestedTab === 'suppliers') {
            abort_unless($user?->role?->canManageStoreOperations() === true, 403);
        }

        $editProductId = $request->query('edit_product');
        $editProductId = is_numeric($editProductId) ? (int) $editProductId : null;
        if ($editProductId !== null) {
            $requestedTab = 'produk';
        }
        $viewData = $dashboardData->forActiveTab(
            $user,
            $requestedTab,
            (string) $request->query('sales_filter', 'all'),
            max(1, (int) $request->query('products_page', 1)),
            [
                'page' => $request->query('page'),
                'status' => $request->query('status'),
                'order_id' => $request->query('order_id'),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
                'limit' => $request->query('limit'),
                'product_id' => $request->query('product_id'),
                'category_id' => $request->query('category_id'),
                'provider' => $request->query('provider'),
                'rail' => $request->query('rail'),
                'source' => $request->query('source'),
                'utm_source' => $request->query('utm_source'),
                'utm_campaign' => $request->query('utm_campaign'),
                'balance_telegram_id' => $request->query('balance_telegram_id'),
                'balance_username' => $request->query('balance_username'),
                'balance_status' => $request->query('balance_status'),
                'balance_age' => $request->query('balance_age'),
                'balance_provider_reference' => $request->query('balance_provider_reference'),
                'balance_page' => $request->query('balance_page'),
            ],
        );
        $dashboardErrors = $request->session()->get('errors', new ViewErrorBag);

        if ($editProductId !== null) {
            $existingPayloads = collect($viewData['productsTab']['products'] ?? []);
            if (! $existingPayloads->contains('id', $editProductId)) {
                $product = Product::query()->find($editProductId);
                if ($product instanceof Product) {
                    $viewData['productsTab']['products'][] = $productsData->productFormPayload($product);
                } else {
                    $editProductId = null;
                }
            }
        }

        $viewData['dashboardState'] = $dashboardState->build(
            $viewData['productsTab'],
            $viewData['statsTab'],
            $viewData['navigation'],
            $dashboardErrors,
            $request->query('category_form') !== null ? (string) $request->query('category_form') : null,
            $request->query('category_id') !== null ? (int) $request->query('category_id') : null,
            $request->query('family_form') !== null ? (string) $request->query('family_form') : null,
            $request->query('family_id') !== null ? (int) $request->query('family_id') : null,
            $editProductId,
        );

        $stockAdded = filter_var($request->query('stock_added'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $viewData['accountStockStatus'] = $stockAdded !== false && $stockAdded !== null
            ? "Stok bertambah {$stockAdded} akun."
            : null;

        if ($viewData['navigation']['activeTab'] === 'produk' && $request->query('product_form') === 'create') {
            $viewData['dashboardState']['productModalOpen'] = true;
        }

        if ($viewData['navigation']['activeTab'] === 'suppliers') {
            $viewData['passwordConfirmedRecently'] = app(SupplierOperationConfirmation::class)->isRecent($request);
        }

        return view('admin.dashboard', $viewData);
    }
}
