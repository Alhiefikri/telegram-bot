<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtReceiptFindingKind;

/**
 * One exact divergence between an external USDT funding receipt and the USDT
 * subledger. Amounts are canonical integer-unit strings (micro-USDT) so a
 * report never rounds, converts implicitly or hides an unmatched deposit.
 */
final readonly class UsdtReceiptFinding
{
    public function __construct(
        public UsdtReceiptFindingKind $kind,
        public ?int $receiptId,
        public ?int $entryId,
        public ?int $customerId,
        public ?string $provider,
        public string $currency,
        public string $targetId,
        public ?string $receiptMicro,
        public ?string $creditedMicro,
        public ?string $differenceMicro,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'receipt_id' => $this->receiptId,
            'entry_id' => $this->entryId,
            'customer_id' => $this->customerId,
            'provider' => $this->provider,
            'currency' => $this->currency,
            'target_id' => $this->targetId,
            'receipt_micro' => $this->receiptMicro,
            'credited_micro' => $this->creditedMicro,
            'difference_micro' => $this->differenceMicro,
        ];
    }
}
