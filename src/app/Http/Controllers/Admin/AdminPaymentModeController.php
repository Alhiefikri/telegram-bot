<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PaymentProviderNotReady;
use App\Exceptions\PaymentProviderSwitchConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentModeRequest;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Admin\PaymentProviderSwitcher;
use App\Services\AuditLogger;
use App\Services\Binance\BinanceVerificationModeManager;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminPaymentModeController extends Controller
{
    public function __invoke(
        UpdatePaymentModeRequest $request,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
        BinanceVerificationModeManager $binanceModes,
        PaymentProviderSwitcher $providerSwitcher,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');
        $validated = $request->validated();
        $updateScope = (string) ($validated['update_scope'] ?? 'provider_switch');
        $providerSwitch = ! in_array($updateScope, ['binance_settings', 'binance_verification'], true);
        $mode = (string) $validated['payment_mode'];
        $checkoutFlow = $mode === 'pakasir'
            ? (string) ($validated['pakasir_checkout_flow'] ?? PakasirCheckoutFlow::HOSTED)
            : null;

        if ($providerSwitch) {
            $expectedVersion = array_key_exists('payment_provider_version', $validated)
                ? (int) $validated['payment_provider_version']
                : (int) (ManualPaymentSetting::current()?->payment_provider_version ?? 0);

            try {
                $result = $providerSwitcher->change(
                    provider: $mode,
                    checkoutFlow: $checkoutFlow,
                    expectedVersion: $expectedVersion,
                    actor: $user instanceof User ? $user : null,
                    ip: $request->ip(),
                );
            } catch (PaymentProviderNotReady|PaymentProviderSwitchConflict $exception) {
                return redirect()
                    ->route('admin.dashboard', ['tab' => 'stats'])
                    ->withErrors(['payment_mode' => $exception->getMessage()], 'paymentMode')
                    ->withInput();
            }

            return $redirector->paymentSettings()
                ->with('status', $this->providerSwitchStatus($result['setting'], $mode, $result['changed']));
        }

        try {
            [$setting, $oldValues] = DB::transaction(function () use (&$mode, $checkoutFlow, $validated, $binanceModes, $providerSwitch, $updateScope): array {
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

                $oldValues = $this->settingValues($setting);

                if ($providerSwitch) {
                    $setting->payment_mode = $mode;
                    if ($checkoutFlow !== null) {
                        $setting->pakasir_checkout_flow = $checkoutFlow;
                    } elseif (! $setting->exists || blank($setting->pakasir_checkout_flow)) {
                        $setting->pakasir_checkout_flow = PakasirCheckoutFlow::HOSTED;
                    }
                    $setting->is_active = true;
                }

                if (array_key_exists('binance_pay_enabled', $validated)) {
                    $setting->binance_pay_enabled = (bool) $validated['binance_pay_enabled'];
                }
                if (array_key_exists('qris_payment_ttl_minutes', $validated)) {
                    $setting->qris_payment_ttl_minutes = $validated['qris_payment_ttl_minutes'];
                }
                if (array_key_exists('binance_payment_ttl_minutes', $validated)) {
                    $setting->binance_payment_ttl_minutes = $validated['binance_payment_ttl_minutes'];
                }
                if (array_key_exists('binance_verification_mode', $validated)) {
                    $setting = $binanceModes->change($setting, (string) $validated['binance_verification_mode']);
                }

                if ($updateScope === 'binance_verification') {
                    $mode = $setting->paymentProvider();
                }

                $setting->save();

                return [$setting, $oldValues];
            }, 5);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.dashboard', ['tab' => 'stats'])
                ->withErrors(['binance_verification_mode' => $exception->getMessage()], 'paymentMode')
                ->withInput();
        }

        $newValues = $this->settingValues($setting);
        $auditLogger->log(
            'payment_mode.updated',
            $setting,
            $oldValues,
            $newValues,
            $user,
            $request->ip(),
        );

        return $redirector->paymentSettings()
            ->with('status', 'Pengaturan pembayaran diperbarui.');
    }

    private function providerSwitchStatus(ManualPaymentSetting $setting, string $mode, bool $changed): string
    {
        if (! $changed) {
            return 'Provider '.PaymentProvider::label($mode).' sudah aktif. Checkout baru tetap memakai provider ini; payment lama tetap memakai provider asal.';
        }

        return match ($mode) {
            'klikqris' => 'KlikQRIS diaktifkan. Checkout baru memakai KlikQRIS; payment lama tetap memakai provider asal.',
            'pakasir' => $setting->pakasirCheckoutFlow() === PakasirCheckoutFlow::API_QRIS
                ? 'Pakasir native QRIS diaktifkan. Checkout baru memakai Pakasir; payment lama tetap memakai provider asal.'
                : 'Pakasir hosted diaktifkan. Checkout baru memakai Pakasir; payment lama tetap memakai provider asal.',
            default => 'QR manual LHIE STORE diaktifkan. Checkout baru memakai Manual; payment lama tetap memakai provider asal.',
        };
    }

    /** @return array<string, mixed> */
    private function settingValues(ManualPaymentSetting $setting): array
    {
        return [
            'provider' => $setting->paymentProvider(),
            'payment_mode' => $setting->payment_mode,
            'pakasir_checkout_flow' => $setting->pakasir_checkout_flow,
            'binance_pay_enabled' => $setting->binance_pay_enabled,
            'qris_payment_ttl_minutes' => $setting->qrisPaymentTtlMinutes(),
            'binance_payment_ttl_minutes' => $setting->binancePaymentTtlMinutes(),
            'binance_verification_mode' => $setting->binance_verification_mode,
        ];
    }
}
