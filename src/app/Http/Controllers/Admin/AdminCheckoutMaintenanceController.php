<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CheckoutMaintenanceConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCheckoutMaintenanceRequest;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use Illuminate\Http\RedirectResponse;

class AdminCheckoutMaintenanceController extends Controller
{
    public function __invoke(
        UpdateCheckoutMaintenanceRequest $request,
        CheckoutMaintenance $maintenance,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        $validated = $request->validated();

        try {
            $change = $maintenance->change(
                active: (bool) $validated['active'],
                publicNote: $validated['public_note'] ?? null,
                expectedVersion: (int) $validated['version'],
                actor: $actor,
                ip: $request->ip(),
            );
        } catch (CheckoutMaintenanceConflict $exception) {
            return $redirector->checkoutMaintenance()
                ->withErrors(['active' => $exception->getMessage()], 'checkoutMaintenance')
                ->withInput();
        }

        if (! $change->changed) {
            return $redirector->checkoutMaintenance()
                ->with('status', $change->newState->active
                    ? 'Checkout Maintenance sudah aktif. Tidak ada perubahan yang disimpan.'
                    : 'Checkout Maintenance sudah nonaktif. Tidak ada perubahan yang disimpan.');
        }

        $status = match (true) {
            ! $change->oldState->active && $change->newState->active => 'Checkout Maintenance diaktifkan. Order baru ditahan, sedangkan katalog, keranjang, dan Order yang sudah ada tetap berjalan.',
            $change->oldState->active && ! $change->newState->active => 'Checkout Maintenance dinonaktifkan. Catatan publik dihapus dan checkout baru dibuka kembali.',
            default => 'Catatan Checkout Maintenance diperbarui.',
        };

        return $redirector->checkoutMaintenance()->with('status', $status);
    }
}
