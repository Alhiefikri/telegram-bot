<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardData;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardData $dashboardData): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        $panel = Filament::getPanel('admin');

        abort_unless($user->canAccessPanel($panel), 403);

        $viewData = $dashboardData->forAdmin($user);
        $tabIds = collect($viewData['tabs'])->pluck('id')->all();
        $requestedTab = (string) $request->query('tab', 'stats');
        $viewData['activeTab'] = in_array($requestedTab, $tabIds, true) ? $requestedTab : 'stats';

        return view('admin.dashboard', $viewData);
    }
}
