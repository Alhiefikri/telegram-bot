<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Models\StoreBalanceTopUp;
use Carbon\CarbonImmutable;
use RuntimeException;

final readonly class KlikQrisPayableBalanceTopUp
{
    private const MAX_TRANSIENT_IMAGE_BYTES = 2_000_000;

    private function __construct(
        private string $qrisUrl,
        private int $creditAmount,
        private int $providerFee,
        private int $totalAmount,
        private string $totalAmountDisplay,
        private CarbonImmutable $expiresAt,
        private ?string $fallbackImageContents,
    ) {}

    public static function fromTopUp(
        StoreBalanceTopUp $topUp,
        ?KlikQrisEnvironment $environment = null,
        bool $refresh = true,
    ): self {
        return self::fromTopUpWithTransientImage($topUp, null, $environment, $refresh);
    }

    public static function fromTopUpWithTransientImage(
        StoreBalanceTopUp $topUp,
        ?string $qrisImageBase64,
        ?KlikQrisEnvironment $environment = null,
        bool $refresh = true,
    ): self {
        $environment ??= KlikQrisEnvironment::runtime();
        if ($refresh) {
            $topUp->refresh();
        }

        if (! $topUp->isPayable()) {
            throw new RuntimeException('Store Balance top-up is not payable.');
        }

        $transaction = KlikQrisTransaction::fromPersistedTopUp($topUp, $environment);
        $qrisUrl = trim((string) $transaction->qrisUrl());
        $providerExpiresAt = $transaction->expiresAt();
        $providerFee = $transaction->providerFee();
        $totalAmount = $transaction->totalAmount();

        if (! $transaction->isPending()
            || $qrisUrl === ''
            || ! KlikQrisTransaction::isAllowedQrisUrl($qrisUrl, environment: $environment)
            || $providerExpiresAt === null
            || $providerFee === null
            || $providerFee < 0
            || $totalAmount < $topUp->amount) {
            throw new RuntimeException('Store Balance top-up provider quote is not payable.');
        }

        $expiresAt = $topUp->expires_at !== null && $topUp->expires_at->lt($providerExpiresAt)
            ? CarbonImmutable::instance($topUp->expires_at)
            : $providerExpiresAt;
        if (! $expiresAt->isFuture()) {
            throw new RuntimeException('Store Balance top-up payment deadline has passed.');
        }

        return new self(
            $qrisUrl,
            (int) $topUp->amount,
            $providerFee,
            $totalAmount,
            $transaction->totalAmountDisplay(),
            $expiresAt,
            self::decodeTransientImage($qrisImageBase64),
        );
    }

    public function qrisUrl(): string
    {
        return $this->qrisUrl;
    }

    public function creditAmount(): int
    {
        return $this->creditAmount;
    }

    public function providerFee(): int
    {
        return $this->providerFee;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function totalAmountDisplay(): string
    {
        return $this->totalAmountDisplay;
    }

    public function effectiveExpiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function fallbackImageContents(): ?string
    {
        return $this->fallbackImageContents;
    }

    private static function decodeTransientImage(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/]+=*)$/', $value, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if (! is_string($decoded)
            || strlen($decoded) > self::MAX_TRANSIENT_IMAGE_BYTES
            || ! str_starts_with($decoded, "\x89PNG\r\n\x1A\n")) {
            return null;
        }

        return $decoded;
    }
}
