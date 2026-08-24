<?php

namespace App\Services\Bsc;

use Illuminate\Support\Carbon;

final readonly class BscReadinessReport
{
    /**
     * @param  list<string>  $missing
     * @param  array<string, string>  $invalid
     */
    public function __construct(
        public bool $enabled,
        public array $missing,
        public array $invalid,
        public int $activeReceiverCount,
        public ?int $rpcChainId = null,
        public ?int $tokenDecimals = null,
        public ?Carbon $checkedAt = null,
    ) {}

    public function isReady(): bool
    {
        return $this->enabled && $this->missing === [] && $this->invalid === [];
    }

    public function configurationMessage(): string
    {
        if (! $this->enabled) {
            return 'BSC rail nonaktif. Aktifkan setelah RPC, token contract, dan receiver terverifikasi siap.';
        }

        $problems = [...$this->missing, ...array_values($this->invalid)];

        return $problems === [] ? 'BSC siap digunakan.' : implode(' ', $problems);
    }
}
