<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerUsdtFundingPauseRequest;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Owner-only Admin adapter for USDT funding/spending/rail pause and resume.
 * Pausing blocks new funding targets (or new USDT spending) only; existing
 * deposits keep being reconciled and settled.
 */
class AdminCustomerUsdtFundingPauseController extends Controller
{
    public function store(
        StoreCustomerUsdtFundingPauseRequest $request,
        CustomerUsdtFundingPolicyManager $policies,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);
        $pauseScope = $request->scope();
        abort_if($pauseScope === null, 404);

        try {
            $changed = $policies->pause($pauseScope, (string) $request->validated('reason'), $actor, $request->ip());
        } catch (InvalidArgumentException $exception) {
            return $redirector->usdtFundingPolicy()
                ->withErrors(['reason' => $exception->getMessage()], 'usdtFundingPolicy')
                ->withInput();
        }

        return $redirector->usdtFundingPolicy()->with(
            'status',
            $changed
                ? $pauseScope->label().' dijeda. Dana yang sudah masuk tetap direkonsiliasi.'
                : $pauseScope->label().' sudah dalam keadaan dijeda.',
        );
    }

    public function destroy(
        Request $request,
        string $scope,
        CustomerUsdtFundingPolicyManager $policies,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);
        $pauseScope = CustomerUsdtFundingPauseScope::tryFrom($scope);
        abort_if($pauseScope === null, 404);

        $changed = $policies->resume($pauseScope, $actor, $request->ip());

        return $redirector->usdtFundingPolicy()->with(
            'status',
            $changed
                ? $pauseScope->label().' dilanjutkan.'
                : $pauseScope->label().' tidak sedang dijeda.',
        );
    }
}
