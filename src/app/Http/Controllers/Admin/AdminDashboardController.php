<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardData;
use App\Services\Admin\AdminProductDashboardState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardData $dashboardData, AdminProductDashboardState $dashboardState): View|RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $viewData = $dashboardData->forActiveTab(
            $user,
            (string) $request->query('tab', 'stats'),
            (string) $request->query('sales_filter', 'all'),
            max(1, (int) $request->query('products_page', 1)),
            [
                'page' => $request->query('page'),
                'status' => $request->query('status'),
                'order_id' => $request->query('order_id'),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
                'limit' => $request->query('limit'),
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
        );

        if ($viewData['navigation']['activeTab'] === 'produk' && $request->query('product_form') === 'create') {
            $viewData['dashboardState']['productModalOpen'] = true;
        }

        return view('admin.dashboard', $viewData);
    }
}
