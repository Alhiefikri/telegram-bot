<?php

namespace App\Services\Admin;

use App\Exceptions\PaymentProviderNotReady;
use App\Exceptions\PaymentProviderSwitchConflict;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;

final class PaymentProviderSwitcher
{
    public function __construct(
        private readonly PakasirProviderReadiness $pakasirReadiness,
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{setting: ManualPaymentSetting, old_provider: string, new_provider: string, changed: bool}
     */
    public function change(
        string $provider,
        ?string $checkoutFlow,
        ?int $expectedVersion,
        ?User $actor,
        ?string $ip,
    ): array {
        $provider = PaymentProvider::normalize($provider);
        $checkoutFlow = $provider === PaymentProvider::PAKASIR
            ? ($checkoutFlow ?: PakasirCheckoutFlow::HOSTED)
            : null;

        return DB::transaction(function () use ($provider, $checkoutFlow, $expectedVersion, $actor, $ip): array {
            $setting = $this->lockedSetting();
            $currentVersion = (int) ($setting->payment_provider_version ?? 0);

            if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
                throw new PaymentProviderSwitchConflict(
                    'Konfigurasi provider berubah sebelum permintaan ini selesai. Muat ulang Ringkasan lalu konfirmasi ulang.',
                );
            }

            $this->assertReady($provider, $checkoutFlow);

            $wasActive = (bool) $setting->is_active;
            $oldProvider = $wasActive ? $setting->paymentProvider() : PaymentProvider::MANUAL;
            $oldCheckoutFlow = $setting->pakasirCheckoutFlow();
            $checkoutFlowChanged = $provider === PaymentProvider::PAKASIR
                && $oldCheckoutFlow !== $checkoutFlow;
            $storedProvider = strtolower(trim((string) $setting->payment_mode));
            $changed = ! $wasActive
                || $storedProvider !== $provider
                || $oldProvider !== $provider
                || $checkoutFlowChanged;

            if (! $changed) {
                return [
                    'setting' => $setting,
                    'old_provider' => $oldProvider,
                    'new_provider' => $provider,
                    'changed' => false,
                ];
            }

            $setting->payment_mode = $provider;
            if ($checkoutFlow !== null) {
                $setting->pakasir_checkout_flow = $checkoutFlow;
            } elseif (! $setting->exists || blank($setting->pakasir_checkout_flow)) {
                $setting->pakasir_checkout_flow = PakasirCheckoutFlow::HOSTED;
            }
            $setting->is_active = true;
            $setting->payment_provider_version = $currentVersion + 1;
            $setting->save();

            // Keep the audit in the same transaction as the setting so
            // a successful switch always has exactly one committed log.
            $this->auditLogger->log(
                'payment_provider.switched',
                $setting,
                ['provider' => $oldProvider],
                ['provider' => $provider],
                $actor,
                $ip,
            );

            return [
                'setting' => $setting,
                'old_provider' => $oldProvider,
                'new_provider' => $provider,
                'changed' => true,
            ];
        }, 5);
    }

    private function lockedSetting(): ManualPaymentSetting
    {
        $setting = ManualPaymentSetting::query()
            ->where('is_active', true)
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        if ($setting !== null) {
            return $setting;
        }

        return ManualPaymentSetting::query()
            ->oldest('id')
            ->lockForUpdate()
            ->first() ?? new ManualPaymentSetting;
    }

    private function assertReady(string $provider, ?string $checkoutFlow): void
    {
        if ($provider === PaymentProvider::PAKASIR) {
            $readiness = $this->pakasirReadiness->checkout($checkoutFlow);
            if (! $readiness->isReady()) {
                throw new PaymentProviderNotReady(
                    'Provider Pakasir belum bisa diaktifkan. '.$readiness->configurationMessage(),
                );
            }

            return;
        }

        if ($provider === PaymentProvider::KLIKQRIS) {
            $readiness = $this->klikQrisReadiness->checkout();
            if (! $readiness->isReady()) {
                throw new PaymentProviderNotReady(
                    'Provider KlikQRIS belum bisa diaktifkan. '.$readiness->configurationMessage(),
                );
            }
        }
    }
}
