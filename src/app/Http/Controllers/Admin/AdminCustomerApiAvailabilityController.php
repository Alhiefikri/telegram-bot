<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CustomerApiAvailabilityConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCustomerApiAvailabilityRequest;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\CustomerApi\CustomerApiAvailability;
use Illuminate\Http\RedirectResponse;

/**
 * Owner-only Admin adapter for the Customer API runtime gate. The controller
 * only delegates; optimistic versioning, audit and fail-closed state live in
 * the availability service and never touch money or Telegram.
 */
class AdminCustomerApiAvailabilityController extends Controller
{
    public function __invoke(
        UpdateCustomerApiAvailabilityRequest $request,
        CustomerApiAvailability $availability,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        $validated = $request->validated();

        try {
            $change = $availability->change(
                enabled: (bool) $validated['enabled'],
                expectedVersion: (int) $validated['version'],
                actor: $actor,
                ip: $request->ip(),
            );
        } catch (CustomerApiAvailabilityConflict $exception) {
            return $redirector->customerApiAvailability()
                ->withErrors(['version' => $exception->getMessage()], 'customerApiAvailability')
                ->withInput();
        }

        if (! $change->changed) {
            return $redirector->customerApiAvailability()
                ->with('status', $change->newState->enabled
                    ? 'Customer API sudah aktif. Tidak ada perubahan yang disimpan.'
                    : 'Customer API sudah dalam maintenance. Tidak ada perubahan yang disimpan.');
        }

        return $redirector->customerApiAvailability()->with('status', $change->newState->enabled
            ? 'Customer API diaktifkan. Endpoint /api/v1 sekarang menerima key yang ada dan dapat membelanjakan saldo.'
            : 'Customer API dimatikan. Endpoint /api/v1 mengembalikan 503 api_maintenance; key, saldo, Order, dan hasil pengiriman tetap tersimpan.');
    }
}
