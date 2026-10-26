<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Exceptions\PaymentProviderNotReady;
use App\Exceptions\PaymentProviderSwitchConflict;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sakurupiah\SakurupiahChannel;
use App\Services\Sakurupiah\SakurupiahChannelPolicy;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SakurupiahChannelSettings
{
    public function __construct(
        private SakurupiahProviderAdapter $provider,
        private SakurupiahProviderReadiness $readiness,
        private AuditLogger $audit,
    ) {}

    /** @return array{channels: list<SakurupiahChannel>, error: ?string} */
    public function available(): array
    {
        $configuration = $this->readiness->configurationReport();
        if (! $configuration->isReady()) {
            return ['channels' => [], 'error' => 'Daftar channel Sakurupiah belum tersedia. '.$configuration->configurationMessage()];
        }

        try {
            $channels = $this->provider->listChannels();
            $counts = array_count_values(array_map(static fn (SakurupiahChannel $channel): string => $channel->code, $channels));
            $offered = array_values(array_filter($channels, fn (SakurupiahChannel $channel): bool => $counts[$channel->code] === 1 && $this->readiness->channelIsSelectable($channel)
            ));

            return [
                'channels' => $offered,
                'error' => $offered === [] ? 'Tidak ada channel QRIS aktif dengan kontrak yang didukung. Muat ulang setelah konfigurasi provider diperbaiki.' : null,
            ];
        } catch (Throwable) {
            return ['channels' => [], 'error' => 'Daftar channel Sakurupiah tidak dapat dimuat dengan aman. Pilihan tersimpan tidak berubah. Muat ulang untuk mencoba lagi.'];
        }
    }

    public function change(string $channel, int $expectedVersion, User $actor, ?string $ip): bool
    {
        if ($actor->role !== UserRole::Owner) {
            throw new AuthorizationException;
        }

        $configurationFingerprint = $this->readiness->configurationReport()->configurationFingerprint();
        $available = $this->available();
        $candidate = null;
        foreach ($available['channels'] as $offered) {
            if ($offered->code === $channel) {
                $candidate = $offered;
                break;
            }
        }
        if ($candidate === null) {
            throw new PaymentProviderNotReady($available['error'] ?? 'Channel tidak aktif atau kontrak channel tidak didukung. Muat ulang pilihan channel.');
        }

        return DB::transaction(function () use ($channel, $candidate, $expectedVersion, $actor, $ip, $configurationFingerprint): bool {
            $setting = ManualPaymentSetting::query()->where('is_active', true)->oldest('id')->lockForUpdate()->first()
                ?? new ManualPaymentSetting(['payment_mode' => 'manual', 'is_active' => true]);
            $version = (int) ($setting->payment_provider_version ?? 0);
            if ($expectedVersion !== $version) {
                throw new PaymentProviderSwitchConflict('Pengaturan pembayaran berubah. Muat ulang lalu pilih channel kembali.');
            }
            $configuration = $this->readiness->configurationReport();
            if (! $configuration->isReady()
                || $configuration->configurationFingerprint() !== $configurationFingerprint
                || ! $this->readiness->channelIsSelectable($candidate)
            ) {
                throw new PaymentProviderNotReady('Konfigurasi channel berubah. Muat ulang pilihan channel.');
            }
            $oldChannel = SakurupiahChannelPolicy::selected($setting);
            if ($oldChannel === $channel) {
                return false;
            }

            $setting->sakurupiah_channel = $channel;
            $setting->payment_provider_version = $version + 1;
            $setting->save();
            $this->audit->log(
                'sakurupiah_channel.updated',
                $setting,
                ['sakurupiah_channel' => $oldChannel, 'payment_provider_version' => $version],
                ['sakurupiah_channel' => $channel, 'payment_provider_version' => $version + 1],
                $actor,
                $ip,
            );
            DB::afterCommit(fn () => $this->readiness->invalidate());

            return true;
        }, 5);
    }
}
