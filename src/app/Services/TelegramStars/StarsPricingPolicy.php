<?php

declare(strict_types=1);

namespace App\Services\TelegramStars;

use InvalidArgumentException;

final readonly class StarsPricingPolicy
{
    public const BASIS_POINTS = 10_000;

    public const MAX_RESERVE_BPS = self::BASIS_POINTS - 1;

    public function __construct(
        public ?int $idrPerUsd,
        public int $rewardMicroUsd,
        public ?int $reserveBps,
        public bool $issuanceEnabled,
        public int $version,
    ) {
        if ($rewardMicroUsd < 1 || $version < 0
            || ($idrPerUsd === null) !== ($reserveBps === null)
            || ($idrPerUsd === null && $issuanceEnabled)
            || ($idrPerUsd !== null && $idrPerUsd < 1)
            || ($reserveBps !== null && ($reserveBps < 0 || $reserveBps > self::MAX_RESERVE_BPS))) {
            throw new InvalidArgumentException('Kebijakan harga Telegram Stars tidak valid.');
        }
    }

    /** @return array{idr_per_usd: ?int, reward_micro_usd: int, reserve_bps: ?int, issuance_enabled: bool, version: int} */
    public function values(): array
    {
        return [
            'idr_per_usd' => $this->idrPerUsd,
            'reward_micro_usd' => $this->rewardMicroUsd,
            'reserve_bps' => $this->reserveBps,
            'issuance_enabled' => $this->issuanceEnabled,
            'version' => $this->version,
        ];
    }
}
