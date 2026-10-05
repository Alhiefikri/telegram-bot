<?php

namespace App\Services\MoneyReceipts;

use InvalidArgumentException;
use JsonException;

/**
 * One verified external money observation, ready to be claimed by a single
 * economic target. The value object is immutable: every field that binds the
 * money — identity, beneficiary, currency, amount units, token precision and
 * target — participates in the fingerprint compared on replay, while
 * progress-only evidence such as confirmations and block observations is
 * retained for audit and never changes ownership.
 */
final readonly class VerifiedMoneyReceipt
{
    public const FINGERPRINT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $evidence  sanitized provider/network evidence
     * @param  list<MoneyReceiptAlias>  $aliases  alternate verified identities for the same funds
     */
    public function __construct(
        public MoneyReceiptIdentity $identity,
        public string $beneficiary,
        public string $currency,
        public string $amountUnits,
        public ?int $decimals,
        public MoneyReceiptTarget $target,
        public array $evidence = [],
        public array $aliases = [],
        public ?MoneyReceiptParentClaim $parentClaim = null,
    ) {
        if ($beneficiary === '' || strlen($beneficiary) > 191 || preg_match('/[^\x21-\x7E]|\|/', $beneficiary) === 1) {
            throw new InvalidArgumentException('Money receipt beneficiary must be a non-empty ASCII value of at most 191 characters.');
        }
        if (preg_match('/^[A-Z0-9]{2,16}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Money receipt currency must be an uppercase code of 2 to 16 characters.');
        }
        if (preg_match('/^[1-9][0-9]{0,77}$/', $amountUnits) !== 1) {
            throw new InvalidArgumentException('Money receipt amount must be a positive integer string without leading zeros or excess precision.');
        }
        if ($decimals !== null && ($decimals < 0 || $decimals > 255)) {
            throw new InvalidArgumentException('Money receipt decimals must be null or between 0 and 255.');
        }
        if ($identity->kind() === MoneyReceiptIdentity::KIND_BSC_LOG) {
            if ($parentClaim === null) {
                throw new InvalidArgumentException('A BSC log receipt must claim its parent transaction.');
            }
            if ($parentClaim->chainId !== $identity->chainId() || $parentClaim->txHash !== $identity->txHash()) {
                throw new InvalidArgumentException('The BSC parent transaction claim must match the receipt log identity.');
            }
        } elseif ($parentClaim !== null) {
            throw new InvalidArgumentException('Only BSC log receipts may claim a parent transaction.');
        }
        if (! array_is_list($aliases)) {
            throw new InvalidArgumentException('Money receipt aliases must be a list.');
        }
        foreach ($aliases as $alias) {
            if (! $alias instanceof MoneyReceiptAlias) {
                throw new InvalidArgumentException('Money receipt aliases must be MoneyReceiptAlias instances.');
            }
        }
        try {
            json_encode($evidence, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Money receipt evidence must be JSON encodable.');
        }
    }

    public function canonicalKey(): string
    {
        return $this->identity->canonicalKey();
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'version' => self::FINGERPRINT_VERSION,
            'identity' => $this->identity->canonicalKey(),
            'beneficiary' => $this->beneficiary,
            'currency' => $this->currency,
            'amount_units' => $this->amountUnits,
            'decimals' => $this->decimals,
            'target_type' => $this->target->type->value,
            'target_id' => $this->target->targetId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
