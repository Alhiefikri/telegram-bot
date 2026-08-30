<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardData;
use App\Services\Admin\AdminProductDashboardState;
use App\Services\Admin\StorefrontFunnelReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardData $dashboardData, AdminProductDashboardState $dashboardState, StorefrontFunnelReport $funnelReport): View|RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $requestedTab = (string) $request->query('tab', 'stats');
        if ($requestedTab === StorefrontFunnelReport::TAB) {
            abort_unless($funnelReport->canView($user), 403);
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
                'utm_medium' => $request->query('utm_medium'),
                'utm_campaign' => $request->query('utm_campaign'),
            ],
        );
        $dashboardErrors = $request->session()->get('errors', new ViewErrorBag);
        $viewData['dashboardState'] = $dashboardState->build(
            $viewData['productsTab'],
            $viewData['statsTab'],
            $viewData['navigation'],
            $dashboardErrors,
            $request->query('category_form') !== null ? (string) $request->query('category_form') : null,
            $request->query('category_id') !== null ? (int) $request->query('category_id') : null,
            $request->query('family_form') !== null ? (string) $request->query('family_form') : null,
            $request->query('family_id') !== null ? (int) $request->query('family_id') : null,
        );

        $stockAdded = filter_var($request->query('stock_added'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $viewData['accountStockStatus'] = $stockAdded !== false && $stockAdded !== null
            ? "Stok bertambah {$stockAdded} akun."
            : null;

        if ($viewData['navigation']['activeTab'] === 'produk' && $request->query('product_form') === 'create') {
            $viewData['dashboardState']['productModalOpen'] = true;
        }

        return view('admin.dashboard', $viewData);
    }
}
