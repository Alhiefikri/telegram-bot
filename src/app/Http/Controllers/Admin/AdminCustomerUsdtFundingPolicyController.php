<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CustomerUsdtFundingPolicyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCustomerUsdtFundingPolicyRequest;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Http\RedirectResponse;

/**
 * Owner-only Admin adapter for the versioned Customer USDT funding policy.
 * The controller only delegates: validation, optimistic version conflict,
 * audit and the DB/Admin boundary all live in the policy manager, and no
 * policy change can ever move money.
 */
class AdminCustomerUsdtFundingPolicyController extends Controller
{
    public function __invoke(
        UpdateCustomerUsdtFundingPolicyRequest $request,
        CustomerUsdtFundingPolicyManager $policies,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        $validated = $request->validated();

        try {
            $change = $policies->change(
                fundingEnabled: $request->boolean('funding_enabled'),
                confirmationTtlSeconds: $validated['confirmation_ttl_seconds'],
                applicationFeeMicro: $validated['application_fee_micro'],
                termsBody: $validated['terms_body'],
                expectedVersion: (int) $validated['version'],
                actor: $actor,
                ip: $request->ip(),
            );
        } catch (CustomerUsdtFundingPolicyConflict $exception) {
            return $redirector->usdtFundingPolicy()
                ->withErrors(['version' => $exception->getMessage()], 'usdtFundingPolicy')
                ->withInput();
        }

        if (! $change->changed) {
            return $redirector->usdtFundingPolicy()
                ->with('status', 'Kebijakan top-up USDT sudah sesuai. Tidak ada perubahan yang disimpan.');
        }

        return $redirector->usdtFundingPolicy()
            ->with('status', 'Kebijakan top-up USDT diperbarui.');
    }
}
