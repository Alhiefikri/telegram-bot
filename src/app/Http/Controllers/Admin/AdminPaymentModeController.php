<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentModeRequest;
use App\Models\ManualPaymentSetting;
use App\Services\AuditLogger;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminPaymentModeController extends Controller
{
    public function __invoke(UpdatePaymentModeRequest $request, AuditLogger $auditLogger, PakasirProviderReadiness $pakasirReadiness): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $validated = $request->validated();
        $mode = (string) $validated['payment_mode'];
        $checkoutFlow = $mode === 'pakasir'
            ? (string) ($validated['pakasir_checkout_flow'] ?? PakasirCheckoutFlow::HOSTED)
            : null;

        [$setting, $oldValues] = DB::transaction(function () use ($mode, $checkoutFlow): array {
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

            $oldValues = $setting->exists
                ? [
                    'payment_mode' => $setting->payment_mode,
                    'pakasir_checkout_flow' => $setting->pakasir_checkout_flow,
                ]
                : [
                    'payment_mode' => null,
                    'pakasir_checkout_flow' => null,
                ];

            $setting->payment_mode = $mode;
            if ($checkoutFlow !== null) {
                $setting->pakasir_checkout_flow = $checkoutFlow;
            } elseif (! $setting->exists || blank($setting->pakasir_checkout_flow)) {
                $setting->pakasir_checkout_flow = PakasirCheckoutFlow::HOSTED;
            }
            $setting->is_active = true;
            $setting->save();

            return [$setting, $oldValues];
        }, 5);

        $newValues = [
            'payment_mode' => $setting->payment_mode,
            'pakasir_checkout_flow' => $setting->pakasir_checkout_flow,
        ];

        $auditLogger->log(
            'payment_mode.updated',
            $setting,
            $oldValues,
            $newValues,
            $user,
            $request->ip(),
        );

        $label = match ($mode) {
            'pakasir' => $setting->pakasirCheckoutFlow() === PakasirCheckoutFlow::API_QRIS
                ? 'Pakasir native QRIS diaktifkan. QR payment_number dikirim di Telegram dengan fallback hosted Pakasir.'
                : 'Pakasir hosted diaktifkan. Checkout baru memakai QR/link website Pakasir.',
            default => 'QR manual LHIE STORE diaktifkan. Checkout baru akan memakai /payment dan approval admin.',
        };

        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->with('status', $label);
    }
}
