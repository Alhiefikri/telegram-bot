<?php

namespace App\Services\CustomerApi;

use App\Exceptions\CustomerApiAvailabilityConflict;
use App\Models\CustomerApiSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the durable Customer API ON/OFF gate. Reads are deliberately uncached:
 * an OFF change must affect the next admitted request in every worker.
 */
final class CustomerApiAvailability
{
    public const MAINTENANCE_RETRY_AFTER = 60;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Read the gate exactly once for an HTTP request. A missing row is OFF; a
     * read failure is distinguishable so the boundary can use the existing
     * temporary-unavailable path instead of claiming a maintenance decision.
     */
    public function check(): CustomerApiAvailabilityCheck
    {
        try {
            $setting = CustomerApiSetting::current();
        } catch (Throwable) {
            return CustomerApiAvailabilityCheck::unavailable();
        }

        if ($setting === null) {
            return CustomerApiAvailabilityCheck::maintenance();
        }

        return $setting->enabled
            ? CustomerApiAvailabilityCheck::enabled()
            : CustomerApiAvailabilityCheck::maintenance();
    }

    public function isEnabled(): bool
    {
        return $this->check()->enabled;
    }

    public function current(): CustomerApiAvailabilityState
    {
        return $this->state(CustomerApiSetting::current());
    }

    public function change(
        bool $enabled,
        int $expectedVersion,
        User $actor,
        ?string $ip,
    ): CustomerApiAvailabilityChange {
        return DB::transaction(function () use ($enabled, $expectedVersion, $actor, $ip): CustomerApiAvailabilityChange {
            // The migration seeds the singleton; recovering a deleted row stays
            // fail-closed and keeps the control usable after manual tampering.
            CustomerApiSetting::query()->insertOrIgnore([
                'singleton_key' => CustomerApiSetting::SINGLETON_KEY,
                'enabled' => false,
                'version' => 0,
                'changed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $setting = CustomerApiSetting::query()
                ->where('singleton_key', CustomerApiSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            $oldState = $this->state($setting);

            if ($expectedVersion !== $oldState->version) {
                throw new CustomerApiAvailabilityConflict(
                    'Status Customer API berubah sebelum permintaan ini selesai. Muat ulang dashboard lalu konfirmasi ulang.',
                );
            }

            if ($oldState->enabled === $enabled) {
                return new CustomerApiAvailabilityChange($oldState, $oldState, false);
            }

            $setting->forceFill([
                'enabled' => $enabled,
                'version' => $oldState->version + 1,
                'changed_at' => now(),
            ])->save();

            $newState = $this->state($setting->fresh());
            $this->auditLogger->log(
                $enabled ? 'customer_api.availability.enabled' : 'customer_api.availability.disabled',
                $setting,
                ['enabled' => $oldState->enabled, 'version' => $oldState->version],
                ['enabled' => $newState->enabled, 'version' => $newState->version],
                $actor,
                $ip,
            );

            return new CustomerApiAvailabilityChange($oldState, $newState, true);
        }, 5);
    }

    private function state(?CustomerApiSetting $setting): CustomerApiAvailabilityState
    {
        if ($setting === null) {
            return new CustomerApiAvailabilityState(false, 0, null);
        }

        $changedAt = $setting->changed_at;

        return new CustomerApiAvailabilityState(
            enabled: (bool) $setting->enabled,
            version: (int) $setting->version,
            changedAt: $changedAt instanceof CarbonImmutable ? $changedAt : null,
        );
    }
}
