<?php

namespace App\Services\Bsc;

use Illuminate\Support\Carbon;

final readonly class BscCapabilityReport
{
    /**
     * @param  array<string, array{status: BscCapabilityStatus, detail: string}>  $capabilities
     */
    public function __construct(
        public array $capabilities,
        public ?int $chainId = null,
        public ?int $tokenDecimals = null,
        public ?Carbon $checkedAt = null,
    ) {}

    /** @return list<string> */
    public static function requiredCapabilities(): array
    {
        return [
            'chain_id',
            'token_contract_code',
            'token_decimals',
            'finalized_block',
            'canonical_receipt',
            'transfer_logs',
        ];
    }

    public function status(string $capability): BscCapabilityStatus
    {
        return $this->capabilities[$capability]['status'] ?? BscCapabilityStatus::Unknown;
    }

    public function detail(string $capability): string
    {
        return $this->capabilities[$capability]['detail'] ?? 'Capability tidak diperiksa.';
    }

    /**
     * RPC capability qualification only. It never means funding, custody,
     * provisioning, or legal activation is authorized.
     */
    public function isQualified(): bool
    {
        foreach (self::requiredCapabilities() as $capability) {
            if (! $this->status($capability)->isVerified()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $capabilities = [];
        foreach ($this->capabilities as $name => $entry) {
            $capabilities[$name] = [
                'status' => $entry['status']->value,
                'detail' => $entry['detail'],
            ];
        }

        return [
            'rpc_qualified' => $this->isQualified(),
            'required' => self::requiredCapabilities(),
            'capabilities' => $capabilities,
            'rpc_chain_id' => $this->chainId,
            'token_decimals' => $this->tokenDecimals,
            'checked_at' => $this->checkedAt?->toIso8601String(),
        ];
    }
}
