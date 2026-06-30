<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\ManualPaymentSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminPaymentModeController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);
        abort_unless((bool) $user->role?->canManagePaymentSettings(), 403);

        $validator = Validator::make($request->all(), [
            'payment_mode' => ['required', 'string', Rule::in(['manual', 'pakasir'])],
        ], [
            'payment_mode.required' => 'Pilih mode pembayaran.',
            'payment_mode.in' => 'Mode pembayaran tidak valid.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('admin.dashboard', ['tab' => 'stats'])
                ->withErrors($validator, 'paymentMode')
                ->withInput();
        }

        $mode = (string) $validator->validated()['payment_mode'];

        [$setting, $oldMode] = DB::transaction(function () use ($mode): array {
            $setting = ManualPaymentSetting::query()
                ->where('is_active', true)
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if (! $setting) {
                $setting = ManualPaymentSetting::query()
                    ->oldest('id')
                    ->lockForUpdate()
                    ->first() ?? new ManualPaymentSetting;
            }

            $oldMode = $setting->exists ? $setting->payment_mode : null;
            $setting->payment_mode = $mode;
            $setting->is_active = true;
            $setting->save();

            return [$setting, $oldMode];
        }, 5);

        $auditLogger->log(
            'payment_mode.updated',
            $setting,
            ['payment_mode' => $oldMode],
            ['payment_mode' => $mode],
            $user,
            $request->ip(),
        );

        $label = $mode === 'pakasir'
            ? 'Pakasir diaktifkan. Checkout baru akan memakai QRIS/hosted Pakasir.'
            : 'Pakasir dimatikan sementara. Checkout baru akan memakai manual /payment dan approval admin.';

        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->with('status', $label);
    }
}
