<?php

namespace App\Services;

use App\Models\Payment;

/**
 * The deployment switch for the lifecycle hardening rollout.
 *
 * Shadow/observe intentionally make no behavioural change. They let a mixed
 * worker fleet run against the additive schema and collect the same report.
 * Canary is deterministic per Payment, so retries never flap between paths.
 */
final class PaymentLifecycleRollout
{
    public const SHADOW = 'shadow';

    public const OBSERVE = 'observe';

    public const CANARY = 'canary';

    public const ENABLED = 'enabled';

    public const ROLLBACK = 'rollback';

    /** @return array<int, string> */
    public function modes(): array
    {
        return [self::SHADOW, self::OBSERVE, self::CANARY, self::ENABLED, self::ROLLBACK];
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('services.payment_lifecycle_rollout.mode', self::ENABLED)));

        return in_array($mode, $this->modes(), true) ? $mode : self::ROLLBACK;
    }

    public function canaryPercentage(): int
    {
        return min(100, max(0, (int) config('services.payment_lifecycle_rollout.canary_percentage', 0)));
    }

    public function usesHardenedPath(Payment $payment): bool
    {
        return match ($this->mode()) {
            self::ENABLED => true,
            self::CANARY => ((int) sprintf('%u', crc32((string) $payment->getKey()))) % 100 < $this->canaryPercentage(),
            default => false,
        };
    }

    /** @return array{mode:string,canary_percentage:int} */
    public function status(): array
    {
        return ['mode' => $this->mode(), 'canary_percentage' => $this->canaryPercentage()];
    }
}
