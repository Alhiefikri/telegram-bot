<?php

namespace App\Services\MoneyReceipts;

use App\Services\Bsc\BscTransactionHash;
use InvalidArgumentException;

/**
 * Canonical physical-money identity of one observed external transfer.
 *
 * Provider-reference identities are scoped by the stable receiving account
 * namespace, never by rotating API credentials. BSC log identities use the
 * existing legacy transaction-hash normalization plus the log index, so the
 * same transfer keeps one identity across adapters and backfill.
 */
final readonly class MoneyReceiptIdentity
{
    public const KIND_PROVIDER_REFERENCE = 'provider_reference';

    public const KIND_BSC_LOG = 'bsc_log';

    private function __construct(
        private string $kind,
        private ?string $provider,
        private ?string $accountNamespace,
        private ?string $reference,
        private ?int $chainId,
        private ?string $txHash,
        private ?int $logIndex,
    ) {}

    public static function providerReference(string $provider, string $accountNamespace, string $reference): self
    {
        $provider = strtolower(trim($provider));
        $accountNamespace = trim($accountNamespace);
        $reference = trim($reference);

        if (preg_match('/^[a-z0-9_]{2,32}$/', $provider) !== 1) {
            throw new InvalidArgumentException('Money receipt provider must be a lowercase namespace of 2 to 32 characters.');
        }
        self::assertSegment('account namespace', $accountNamespace, 64);
        self::assertSegment('reference', $reference, 64);

        return new self(
            kind: self::KIND_PROVIDER_REFERENCE,
            provider: $provider,
            accountNamespace: $accountNamespace,
            reference: $reference,
            chainId: null,
            txHash: null,
            logIndex: null,
        );
    }

    public static function binancePay(string $receiverType, string $receiverValue, string $transactionId): self
    {
        $receiverType = strtolower(trim($receiverType));
        $receiverValue = trim($receiverValue);
        if (! in_array($receiverType, ['pay_id', 'uid'], true)) {
            throw new InvalidArgumentException('Binance receiver type must be pay_id or uid.');
        }
        if ($receiverValue === '') {
            throw new InvalidArgumentException('Binance receiver value must not be empty.');
        }

        return self::providerReference('binance', $receiverType.':'.$receiverValue, $transactionId);
    }

    public static function bscLog(int $chainId, string $txHash, int $logIndex): self
    {
        if ($chainId <= 0) {
            throw new InvalidArgumentException('BSC receipt chain id must be positive.');
        }
        if ($logIndex < 0) {
            throw new InvalidArgumentException('BSC receipt log index must not be negative.');
        }

        return new self(
            kind: self::KIND_BSC_LOG,
            provider: 'bsc',
            accountNamespace: null,
            reference: null,
            chainId: $chainId,
            txHash: BscTransactionHash::require($txHash),
            logIndex: $logIndex,
        );
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function accountNamespace(): ?string
    {
        return $this->accountNamespace;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function chainId(): ?int
    {
        return $this->chainId;
    }

    public function txHash(): ?string
    {
        return $this->txHash;
    }

    public function logIndex(): ?int
    {
        return $this->logIndex;
    }

    public function canonicalKey(): string
    {
        return match ($this->kind) {
            self::KIND_PROVIDER_REFERENCE => 'v1|provider_reference|'.$this->provider.'|'.$this->accountNamespace.'|'.$this->reference,
            self::KIND_BSC_LOG => 'v1|bsc_log|chain:'.$this->chainId.'|tx:'.$this->txHash.'|log:'.$this->logIndex,
        };
    }

    private static function assertSegment(string $label, string $value, int $maxLength): void
    {
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[^\x21-\x7E]|\|/', $value) === 1) {
            throw new InvalidArgumentException("Money receipt {$label} must be a non-empty ASCII value of at most {$maxLength} characters.");
        }
    }
}
