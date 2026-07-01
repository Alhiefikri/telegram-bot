<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, AdminDashboardData $dashboardData): View|RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        $viewData = $dashboardData->forAdmin($user, (string) $request->query('tab', 'stats'));

        return view('admin.dashboard', $viewData);
    }
}
