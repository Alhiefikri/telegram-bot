<?php

namespace App\Services\UsdtTopUps;

use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtTopUp;
use Carbon\CarbonImmutable;

/**
 * Read-only display state for one confirmed USDT funding target. Every value
 * comes from the immutable target snapshot plus durable work/resolution rows;
 * the status is honest presentation only and never money authority.
 */
final class UsdtTopUpStatus
{
    public const STATE_PREPARING = 'preparing';

    public const STATE_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATE_EXPIRED_UNRESOLVED = 'expired_unresolved';

    public const STATE_REVIEW = 'manual_review';

    public const STATE_SETTLED = 'settled';

    public const STATE_COMPENSATED = 'resolved_by_compensation';

    public const STATE_EXTERNALLY_REFUNDED = 'externally_refunded';

    public const STATE_REJECTED = 'rejected';

    /** @var list<string> */
    public const TERMINAL = [
        self::STATE_SETTLED,
        self::STATE_COMPENSATED,
        self::STATE_EXTERNALLY_REFUNDED,
        self::STATE_REJECTED,
    ];

    private function __construct(
        public readonly string $state,
        public readonly string $reference,
        public readonly int $creditMicro,
        public readonly int $feeMicro,
        public readonly int $payableMicro,
        public readonly ?string $receiverAddress,
        public readonly ?int $chainId,
        public readonly ?string $tokenContract,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $deadline,
        public readonly ?int $receivedMicro,
        public readonly ?int $balanceBeforeMicro,
        public readonly ?int $balanceAfterMicro,
    ) {}

    public static function for(
        CustomerUsdtTopUp $target,
        string $state,
        ?CustomerUsdtBalanceEntry $entry = null,
        ?int $receivedMicro = null,
    ): self {
        $snapshot = is_array($target->payment_snapshot) ? $target->payment_snapshot : [];

        return new self(
            state: $state,
            reference: (string) $target->reference,
            creditMicro: $entry === null ? (int) $target->amount_micro : (int) $entry->amount_micro,
            feeMicro: (int) $target->fee_micro,
            payableMicro: (int) $target->payable_micro,
            receiverAddress: isset($snapshot['receiver_address']) ? (string) $snapshot['receiver_address'] : null,
            chainId: isset($snapshot['chain_id']) ? (int) $snapshot['chain_id'] : null,
            tokenContract: isset($snapshot['token_contract']) ? (string) $snapshot['token_contract'] : null,
            createdAt: CarbonImmutable::instance($target->created_at),
            deadline: CarbonImmutable::instance($target->expires_at),
            receivedMicro: $receivedMicro,
            balanceBeforeMicro: $entry === null ? null : (int) $entry->balance_after_micro - (int) $entry->amount_micro,
            balanceAfterMicro: $entry === null ? null : (int) $entry->balance_after_micro,
        );
    }

    public function isAwaitingPayment(): bool
    {
        return $this->state === self::STATE_AWAITING_PAYMENT;
    }

    public function isSettled(): bool
    {
        return $this->state === self::STATE_SETTLED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL, true);
    }

    /** Lookup hints and refreshes remain meaningful until a resolution exists. */
    public function acceptsCustomerLookup(): bool
    {
        return ! $this->isTerminal();
    }
}
