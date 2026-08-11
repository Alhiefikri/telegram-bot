<?php

namespace App\Services\CheckoutMaintenance;

use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\CheckoutMaintenanceConflict;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CheckoutMaintenance
{
    public const MAX_NOTE_LENGTH = 500;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function current(): CheckoutMaintenanceState
    {
        $setting = CheckoutMaintenanceSetting::query()
            ->where('singleton_key', CheckoutMaintenanceSetting::SINGLETON_KEY)
            ->first();

        return $this->state($setting);
    }

    public function guardNewOrder(): void
    {
        $setting = CheckoutMaintenanceSetting::query()
            ->where('singleton_key', CheckoutMaintenanceSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->firstOrFail();

        $state = $this->state($setting);
        if ($state->active) {
            throw new CheckoutMaintenanceActive($state);
        }
    }

    public function change(
        bool $active,
        ?string $publicNote,
        int $expectedVersion,
        User $actor,
        ?string $ip,
    ): CheckoutMaintenanceChange {
        $normalizedNote = $this->normalizeNote($publicNote);

        return DB::transaction(function () use ($active, $normalizedNote, $expectedVersion, $actor, $ip): CheckoutMaintenanceChange {
            $setting = CheckoutMaintenanceSetting::query()
                ->where('singleton_key', CheckoutMaintenanceSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            $oldState = $this->state($setting);

            if ($expectedVersion !== $oldState->version) {
                throw new CheckoutMaintenanceConflict(
                    'Checkout Maintenance berubah sebelum permintaan ini selesai. Muat ulang dashboard lalu konfirmasi ulang.',
                );
            }

            $nextNote = $active ? $normalizedNote : null;
            $changed = $oldState->active !== $active || $oldState->publicNote !== $nextNote;
            if (! $changed) {
                return new CheckoutMaintenanceChange($oldState, $oldState, false);
            }

            $activatedAt = $active
                ? ($oldState->active ? $oldState->activatedAt : now())
                : null;
            $setting->forceFill([
                'is_active' => $active,
                'public_note' => $nextNote,
                'version' => $oldState->version + 1,
                'activated_at' => $activatedAt,
            ])->save();

            $newState = $this->state($setting->fresh());
            $this->auditLogger->log(
                $this->auditAction($oldState, $newState),
                $setting,
                $this->auditValues($oldState),
                $this->auditValues($newState),
                $actor,
                $ip,
            );

            return new CheckoutMaintenanceChange($oldState, $newState, true);
        }, 5);
    }

    private function normalizeNote(?string $publicNote): ?string
    {
        if ($publicNote === null) {
            return null;
        }

        $normalized = trim($publicNote);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > self::MAX_NOTE_LENGTH) {
            throw new InvalidArgumentException('Checkout Maintenance note maksimal 500 karakter.');
        }

        return $normalized;
    }

    private function state(?CheckoutMaintenanceSetting $setting): CheckoutMaintenanceState
    {
        if ($setting === null) {
            return new CheckoutMaintenanceState(false, null, 0, null);
        }

        $active = (bool) $setting->is_active;

        return new CheckoutMaintenanceState(
            active: $active,
            publicNote: $active ? $setting->public_note : null,
            version: (int) $setting->version,
            activatedAt: $active && $setting->activated_at instanceof Carbon ? $setting->activated_at : null,
        );
    }

    /** @return array{active: bool, public_note: ?string} */
    private function auditValues(CheckoutMaintenanceState $state): array
    {
        return [
            'active' => $state->active,
            'public_note' => $state->publicNote,
        ];
    }

    private function auditAction(CheckoutMaintenanceState $oldState, CheckoutMaintenanceState $newState): string
    {
        return match (true) {
            ! $oldState->active && $newState->active => 'checkout_maintenance.activated',
            $oldState->active && ! $newState->active => 'checkout_maintenance.deactivated',
            default => 'checkout_maintenance.updated',
        };
    }
}
