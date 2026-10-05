<?php

namespace App\Services\UsdtTreasury;

use App\Enums\UsdtTreasuryAssetState;
use Brick\Math\BigInteger;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One approved custody/provider asset observation for USDT. `Available`
 * carries a proven exact amount; `Unavailable` and `Unsupported` mean the
 * backing cannot be proven at all, which is a different fact from a zero
 * balance and must never be reported as one. Provider keys and reasons are
 * restricted to sanitized tokens, so an observation can be logged in a
 * reconciliation report without leaking secrets or customer data.
 */
final readonly class UsdtTreasuryAssetObservation
{
    public const PROVIDER_PATTERN = '/^[a-z0-9][a-z0-9_\-]{1,31}$/';

    public const REASON_PATTERN = '/^[a-z0-9][a-z0-9_\-:.]{0,63}$/';

    private function __construct(
        public string $provider,
        public UsdtTreasuryAssetState $state,
        public ?BigInteger $assetsMicro,
        public ?string $reason,
        public ?DateTimeInterface $observedAt,
    ) {
        if (preg_match(self::PROVIDER_PATTERN, $provider) !== 1) {
            throw new InvalidArgumentException('A treasury asset provider key must be a lowercase sanitized token of at most 32 characters.');
        }

        if ($reason !== null && preg_match(self::REASON_PATTERN, $reason) !== 1) {
            throw new InvalidArgumentException('A treasury asset observation reason must be a lowercase sanitized token of at most 64 characters.');
        }
    }

    public static function available(
        string $provider,
        BigInteger|int|string $assetsMicro,
        DateTimeInterface $observedAt,
    ): self {
        $assets = BigInteger::of($assetsMicro);
        if ($assets->isNegative()) {
            throw new InvalidArgumentException('A treasury asset observation cannot be negative.');
        }

        return new self($provider, UsdtTreasuryAssetState::Available, $assets, null, $observedAt);
    }

    public static function unavailable(string $provider, string $reason): self
    {
        return new self($provider, UsdtTreasuryAssetState::Unavailable, null, $reason, null);
    }

    public static function unsupported(string $provider, string $reason): self
    {
        return new self($provider, UsdtTreasuryAssetState::Unsupported, null, $reason, null);
    }

    public function assetsMicroString(): ?string
    {
        return $this->assetsMicro?->toString();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'state' => $this->state->value,
            'assets_micro' => $this->assetsMicroString(),
            'reason' => $this->reason,
            'observed_at' => $this->observedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
