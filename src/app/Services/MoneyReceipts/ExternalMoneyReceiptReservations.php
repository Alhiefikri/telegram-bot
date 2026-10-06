<?php

namespace App\Services\MoneyReceipts;

use App\Models\ExternalMoneyReceiptReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Durable denylist for legacy money evidence that cannot be normalized into a
 * claimed ExternalMoneyReceipt. Reserving is idempotent; a reservation is
 * resolved only when the same legacy target is later claimed or when an
 * operator explicitly clears it. Open blocking reservations gate the cutover.
 */
final class ExternalMoneyReceiptReservations
{
    public const SOURCE_RUNTIME = 'runtime';

    public function reserve(
        string $source,
        string $legacyType,
        string $legacyId,
        ?string $provider,
        string $reason,
        array $details = [],
    ): bool {
        $source = $this->segment('source', $source, 64);
        $legacyType = $this->segment('legacy type', $legacyType, 32);
        $legacyId = $this->segment('legacy id', $legacyId, 64);
        $reason = $this->segment('reason', $reason, 64);
        $provider = $provider === null || trim($provider) === ''
            ? null
            : $this->segment('provider', strtolower(trim($provider)), 32);

        $now = now();

        return DB::table('external_money_receipt_reservations')->insertOrIgnore([
            'source' => $source,
            'legacy_type' => $legacyType,
            'legacy_id' => $legacyId,
            'provider' => $provider,
            'reason' => $reason,
            'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'reserved_at' => $now,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    public function resolve(string $source, string $legacyType, string $legacyId): int
    {
        return ExternalMoneyReceiptReservation::query()
            ->where('source', $source)
            ->where('legacy_type', $legacyType)
            ->where('legacy_id', $legacyId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    public function resolveReason(string $legacyType, string $legacyId, string $reason): int
    {
        return ExternalMoneyReceiptReservation::query()
            ->where('legacy_type', $legacyType)
            ->where('legacy_id', $legacyId)
            ->where('reason', $reason)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    public function resolveById(int $id): ?ExternalMoneyReceiptReservation
    {
        $reservation = ExternalMoneyReceiptReservation::query()->whereKey($id)->first();
        if ($reservation === null || $reservation->resolved_at !== null) {
            return $reservation;
        }

        $reservation->forceFill(['resolved_at' => now()])->save();

        return $reservation->refresh();
    }

    public function openCount(?string $source = null): int
    {
        return ExternalMoneyReceiptReservation::query()
            ->whereNull('resolved_at')
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->count();
    }

    /** @return Collection<int, ExternalMoneyReceiptReservation> */
    public function open(): Collection
    {
        return ExternalMoneyReceiptReservation::query()
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->get();
    }

    private function segment(string $label, string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Money receipt reservation {$label} must be a non-empty value of at most {$maxLength} characters.");
        }

        return $value;
    }
}
