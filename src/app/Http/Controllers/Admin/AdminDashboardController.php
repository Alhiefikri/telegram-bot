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

        $viewData = $dashboardData->forAdmin(
            $user,
            (string) $request->query('tab', 'stats'),
            (string) $request->query('sales_filter', 'all'),
            max(1, (int) $request->query('products_page', 1)),
        );
        $viewData['dashboardState'] = $dashboardState->build(
            $viewData['productsTab'],
            $viewData['statsTab'],
            $viewData['navigation'],
            $request->session()->get('errors', new ViewErrorBag),
        );

        return view('admin.dashboard', $viewData);
    }
}
