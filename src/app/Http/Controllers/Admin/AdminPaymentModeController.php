<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentModeRequest;
use App\Models\ManualPaymentSetting;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\AuditLogger;
use App\Services\Binance\BinanceVerificationModeManager;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminPaymentModeController extends Controller
{
    public function __invoke(UpdatePaymentModeRequest $request, AuditLogger $auditLogger, PakasirProviderReadiness $pakasirReadiness, AdminDashboardRedirector $redirector, BinanceVerificationModeManager $binanceModes): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $validated = $request->validated();
        $binanceOnly = ($validated['update_scope'] ?? null) === 'binance_verification';
        $mode = (string) $validated['payment_mode'];
        $checkoutFlow = $mode === 'pakasir'
            ? (string) ($validated['pakasir_checkout_flow'] ?? PakasirCheckoutFlow::HOSTED)
            : null;

        try {
            [$setting, $oldValues] = DB::transaction(function () use (&$mode, $checkoutFlow, $validated, $binanceModes, $binanceOnly): array {
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
                    'binance_pay_enabled' => $setting->binance_pay_enabled,
                    'qris_payment_ttl_minutes' => $setting->qrisPaymentTtlMinutes(),
                    'binance_payment_ttl_minutes' => $setting->binancePaymentTtlMinutes(),
                    'binance_verification_mode' => $setting->binance_verification_mode,
                ]
                : [
                    'payment_mode' => null,
                    'pakasir_checkout_flow' => null,
                    'binance_pay_enabled' => false,
                    'qris_payment_ttl_minutes' => ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES,
                    'binance_payment_ttl_minutes' => ManualPaymentSetting::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES,
                    'binance_verification_mode' => 'amount_unique',
                ];

            if ($binanceOnly) {
                $mode = (string) ($setting->payment_mode ?: 'manual');
            } else {
                $setting->payment_mode = $mode;
                if ($checkoutFlow !== null) {
                    $setting->pakasir_checkout_flow = $checkoutFlow;
                } elseif (! $setting->exists || blank($setting->pakasir_checkout_flow)) {
                    $setting->pakasir_checkout_flow = PakasirCheckoutFlow::HOSTED;
                }
                $setting->is_active = true;
                if (array_key_exists('binance_pay_enabled', $validated)) {
                    $setting->binance_pay_enabled = (bool) $validated['binance_pay_enabled'];
                }
                if (array_key_exists('qris_payment_ttl_minutes', $validated)) {
                    $setting->qris_payment_ttl_minutes = $validated['qris_payment_ttl_minutes'];
                }
                if (array_key_exists('binance_payment_ttl_minutes', $validated)) {
                    $setting->binance_payment_ttl_minutes = $validated['binance_payment_ttl_minutes'];
                }
            }
            if (array_key_exists('binance_verification_mode', $validated)) {
                $setting = $binanceModes->change($setting, (string) $validated['binance_verification_mode']);
            }
            $setting->save();

                return [$setting, $oldValues];
            }, 5);
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('admin.dashboard', ['tab' => 'stats'])
                ->withErrors(['binance_verification_mode' => $exception->getMessage()], 'paymentMode')
                ->withInput();
        }

        $newValues = [
            'payment_mode' => $setting->payment_mode,
            'pakasir_checkout_flow' => $setting->pakasir_checkout_flow,
            'binance_pay_enabled' => $setting->binance_pay_enabled,
            'qris_payment_ttl_minutes' => $setting->qrisPaymentTtlMinutes(),
            'binance_payment_ttl_minutes' => $setting->binancePaymentTtlMinutes(),
            'binance_verification_mode' => $setting->binance_verification_mode,
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

        return $redirector->paymentSettings()
            ->with('status', $label);
    }
}
