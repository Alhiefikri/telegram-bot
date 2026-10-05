<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use InvalidArgumentException;

/**
 * Closed set of typed economic sources that can authorize one Customer USDT
 * Balance posting. Callers cannot select an arbitrary source string: the
 * ledger derives the economic identity and the versioned request fingerprint
 * from the concrete source object, and a replay must present the same payload.
 * Each source binds exact positive micro-USDT money, so every amount crosses
 * the strict UsdtMicroAmount boundary before it can reach the ledger.
 */
abstract class UsdtBalanceSource
{
    private const FINGERPRINT_VERSION = 1;

    protected function __construct(
        public readonly int $customerId,
        public readonly UsdtMicroAmount $amount,
    ) {
        if ($customerId <= 0) {
            throw new InvalidArgumentException('USDT balance source requires an existing Customer id.');
        }
    }

    abstract public function kind(): UsdtBalanceEntryKind;

    abstract public function sourceType(): string;

    abstract public function sourceId(): string;

    public function originalEntryId(): ?int
    {
        return null;
    }

    public function refundOrderId(): ?string
    {
        return null;
    }

    final public function amountMicro(): int
    {
        return $this->amount->units();
    }

    final public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'version' => self::FINGERPRINT_VERSION,
            'variant' => $this->variant(),
            'kind' => $this->kind()->value,
            'currency' => $this->amount->currency(),
            'customer_id' => $this->customerId,
            'amount_micro' => $this->amountMicro(),
        ] + $this->fingerprintFields(), JSON_THROW_ON_ERROR));
    }

    abstract protected function variant(): string;

    /** @return array<string, int|string> */
    abstract protected function fingerprintFields(): array;

    final protected static function assertIdentifier(string $label, string $value, int $maxLength = 64): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("USDT balance {$label} must be a non-empty value of at most {$maxLength} characters.");
        }
    }
}
