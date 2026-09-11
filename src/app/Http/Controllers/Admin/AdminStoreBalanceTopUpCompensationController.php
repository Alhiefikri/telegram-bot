<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StoreBalanceCompensationRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReleaseStoreBalanceTopUpRequest;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use Illuminate\Http\RedirectResponse;

final class AdminStoreBalanceTopUpCompensationController extends Controller
{
    public function __invoke(
        ReleaseStoreBalanceTopUpRequest $request,
        string $topUp,
        StoreBalanceOwnerCompensationService $compensations,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        $validated = $request->validated();

        try {
            $result = $compensations->release(
                topUpId: $topUp,
                actor: $actor,
                providerReference: (string) $validated['provider_reference'],
                reason: (string) $validated['reason'],
                ip: $request->ip(),
            );
        } catch (StoreBalanceCompensationRejected $exception) {
            return $redirector->balanceIssues()
                ->withErrors(['release' => $exception->getMessage()], 'balanceCompensation')
                ->withInput();
        }

        return $redirector->balanceIssues()->with(
            'status',
            $result->applied
                ? 'Saldo customer berhasil dirilis sesuai nominal top-up asli.'
                : 'Kompensasi top-up sudah tercatat sebelumnya. Saldo tidak ditambah lagi.',
        );
    }
}
