<?php

namespace App\Services\Admin;

use App\Exceptions\PaymentProviderNotReady;
use App\Exceptions\PaymentProviderSwitchConflict;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;

final class PaymentProviderSwitcher
{
    public function __construct(
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{setting: ManualPaymentSetting, old_provider: string, new_provider: string, changed: bool}
     */
    public function change(
        string $provider,
        ?int $expectedVersion,
        ?User $actor,
        ?string $ip,
    ): array {
        $provider = PaymentProvider::normalize($provider);
        $this->assertReady($provider);

        return DB::transaction(function () use ($provider, $expectedVersion, $actor, $ip): array {
            $setting = $this->lockedSetting();
            $currentVersion = (int) ($setting->payment_provider_version ?? 0);

            if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
                throw new PaymentProviderSwitchConflict(
                    'Konfigurasi provider berubah sebelum permintaan ini selesai. Muat ulang Ringkasan lalu konfirmasi ulang.',
                );
            }

            $wasActive = (bool) $setting->is_active;
            $oldProvider = $wasActive ? $setting->paymentProvider() : PaymentProvider::MANUAL;
            $storedProvider = strtolower(trim((string) $setting->payment_mode));
            $changed = ! $wasActive
                || $storedProvider !== $provider
                || $oldProvider !== $provider;
            if (! $changed) {
                return [
                    'setting' => $setting,
                    'old_provider' => $oldProvider,
                    'new_provider' => $provider,
                    'changed' => false,
                ];
            }

            $setting->payment_mode = $provider;
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

    private function assertReady(string $provider): void
    {
        if (! in_array($provider, PaymentProvider::allowed(), true)) {
            throw new PaymentProviderNotReady('Provider '.$provider.' sudah dipensiunkan untuk checkout baru.');
        }

        if ($provider === PaymentProvider::SAKURUPIAH) {
            $readiness = $this->sakurupiahReadiness->observe();
            if (! $readiness->isReady()) {
                throw new PaymentProviderNotReady(
                    'Provider Sakurupiah belum bisa diaktifkan. '.$readiness->configurationMessage(),
                );
            }

            return;
        }

        if ($provider !== PaymentProvider::KLIKQRIS) {
            return;
        }

        $readiness = $this->klikQrisReadiness->checkout();
        if (! $readiness->isReady()) {
            throw new PaymentProviderNotReady(
                'Provider KlikQRIS belum bisa diaktifkan. '.$readiness->configurationMessage(),
            );
        }
    }
}
