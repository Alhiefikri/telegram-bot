<?php

namespace App\Services\TelegramStars;

use App\Exceptions\StarsPricingPolicyConflict;
use App\Models\StarsPricingPolicyRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class StarsPricingPolicyManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function current(): StarsPricingPolicy
    {
        return $this->snapshot(StarsPricingPolicyRecord::query()->findOrFail(StarsPricingPolicyRecord::SINGLETON_ID));
    }

    public function lockForCheckout(): StarsPricingPolicy
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Telegram Stars policy lock requires a checkout transaction.');
        }

        return $this->snapshot(StarsPricingPolicyRecord::query()->sharedLock()->findOrFail(StarsPricingPolicyRecord::SINGLETON_ID));
    }

    public function change(User $actor, StarsPricingPolicy $proposal, ?string $ip): bool
    {
        if (! $actor->role?->canManageStarsPricing()) {
            throw new AuthorizationException;
        }

        if ($proposal->idrPerUsd === null) {
            throw new InvalidArgumentException('Kurs dan cadangan Telegram Stars wajib diisi.');
        }

        return DB::transaction(function () use ($actor, $proposal, $ip): bool {
            $record = StarsPricingPolicyRecord::query()
                ->lockForUpdate()
                ->findOrFail(StarsPricingPolicyRecord::SINGLETON_ID);
            $before = $this->snapshot($record)->values();
            $after = $proposal->values();

            if ($before['version'] !== $after['version']) {
                throw new StarsPricingPolicyConflict('Kebijakan harga sudah berubah. Tinjau versi terbaru sebelum menyimpan lagi.');
            }

            if ($before === $after) {
                return false;
            }

            if ($after['version'] === PHP_INT_MAX) {
                throw new StarsPricingPolicyConflict('Versi kebijakan harga telah mencapai batas.');
            }

            $after['version']++;
            if (! $record->forceFill($after)->save()) {
                throw new RuntimeException('Could not persist the Telegram Stars pricing policy.');
            }

            $this->auditLogger->logWithResolvedContext('telegram_stars.pricing_policy.updated', $record, $before, $after, $actor, $ip);

            return true;
        }, 3);
    }

    private function snapshot(StarsPricingPolicyRecord $record): StarsPricingPolicy
    {
        return new StarsPricingPolicy(
            $record->idr_per_usd,
            $record->reward_micro_usd,
            $record->reserve_bps,
            $record->issuance_enabled,
            $record->version,
        );
    }
}
