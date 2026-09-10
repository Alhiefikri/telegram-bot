<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StoreBalanceFundingPolicyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStoreBalanceFundingPolicyRequest;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use Illuminate\Http\RedirectResponse;

class AdminStoreBalanceFundingPolicyController extends Controller
{
    public function __invoke(
        UpdateStoreBalanceFundingPolicyRequest $request,
        StoreBalanceFundingPolicyManager $policies,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        $validated = $request->validated();

        try {
            $change = $policies->change(
                minimumAmount: $validated['minimum_amount'],
                presetAmounts: $validated['preset_amounts'],
                expectedVersion: (int) $validated['version'],
                actor: $actor,
                ip: $request->ip(),
            );
        } catch (StoreBalanceFundingPolicyConflict $exception) {
            return $redirector->fundingPolicy()
                ->withErrors(['version' => $exception->getMessage()], 'fundingPolicy')
                ->withInput();
        }

        if (! $change->changed) {
            return $redirector->fundingPolicy()
                ->with('status', 'Kebijakan top-up Saldo sudah sesuai. Tidak ada perubahan yang disimpan.');
        }

        return $redirector->fundingPolicy()
            ->with('status', 'Kebijakan top-up Saldo diperbarui.');
    }
}
