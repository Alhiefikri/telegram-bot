<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardData;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardData $dashboardData, AdminProductDashboardFormProtocol $forms): View|RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $viewData = $dashboardData->forAdmin($user, (string) $request->query('tab', 'stats'));
        $viewData['dashboardState'] = $forms->dashboardState(
            $viewData['productsTab'],
            $viewData['statsTab'],
            $viewData['navigation'],
            $request->session()->get('errors', new ViewErrorBag),
        );

        return view('admin.dashboard', $viewData);
    }
}
