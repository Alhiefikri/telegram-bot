<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentModeRequest;
use App\Models\ManualPaymentSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminPaymentModeController extends Controller
{
    public function __invoke(UpdatePaymentModeRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $mode = (string) $request->validated('payment_mode');

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
            : 'QR manual LHIE STORE diaktifkan. Checkout baru akan memakai /payment dan approval admin.';

        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->with('status', $label);
    }
}
