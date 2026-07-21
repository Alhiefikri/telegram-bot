<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSupportSettingsRequest;
use App\Models\ManualPaymentSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminSupportSettingsController extends Controller
{
    public function __invoke(UpdateSupportSettingsRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $validated = $request->validated();

        [$setting, $oldValues] = DB::transaction(function () use ($validated): array {
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

            $oldValues = [
                'whatsapp_support_url' => $setting->whatsapp_support_url,
                'whatsapp_support_number' => $setting->whatsappSupportNumber(),
            ];

            $setting->payment_mode = $setting->payment_mode ?: 'manual';
            $setting->is_active = true;
            $setting->whatsapp_support_url = $this->blankToNull($validated['whatsapp_support_url'] ?? null);
            $setting->whatsapp_support_number = $this->blankToNull(
                ManualPaymentSetting::normalizeWhatsappNumber((string) ($validated['whatsapp_support_number'] ?? ''))
            );
            $setting->save();

            return [$setting, $oldValues];
        }, 5);

        $newValues = [
            'whatsapp_support_url' => $setting->whatsapp_support_url,
            'whatsapp_support_number' => $setting->whatsappSupportNumber(),
        ];

        $auditLogger->log(
            'support_settings.updated',
            $setting,
            $oldValues,
            $newValues,
            $user,
            $request->ip(),
        );

        return redirect()
            ->route('admin.dashboard', ['tab' => 'stats'])
            ->with('status', 'Support WhatsApp diperbarui.');
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
