<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceDriftIssue;

/**
 * One account whose projection, cursor and immutable signed history do not
 * agree exactly. Amounts are signed-ledger truth versus stored projection, so
 * a finding is evidence for a scoped freeze and operator review — never for
 * an automatic repair or balance rewrite.
 */
final readonly class UsdtBalanceDriftFinding
{
    /**
     * @param  list<UsdtBalanceDriftIssue>  $issues
     */
    public function __construct(
        public int $accountId,
        public int $customerId,
        public int $projectedMicro,
        public int $ledgerMicro,
        public int $version,
        public ?int $lastEntryId,
        public int $entryCount,
        public ?int $latestSequence,
        public ?int $latestBalanceAfterMicro,
        public array $issues,
    ) {}

    public function freezeReason(): string
    {
        return 'reconciliation_drift:'.$this->issues[0]->value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'customer_id' => $this->customerId,
            'projected_micro' => (string) $this->projectedMicro,
            'ledger_micro' => (string) $this->ledgerMicro,
            'version' => $this->version,
            'last_entry_id' => $this->lastEntryId,
            'entry_count' => $this->entryCount,
            'latest_sequence' => $this->latestSequence,
            'latest_balance_after_micro' => $this->latestBalanceAfterMicro === null
                ? null
                : (string) $this->latestBalanceAfterMicro,
            'issues' => array_map(static fn (UsdtBalanceDriftIssue $issue): string => $issue->value, $this->issues),
        ];
    }
}
