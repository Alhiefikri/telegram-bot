<?php

namespace App\Services\StoreBalance;

final readonly class StoreBalanceTopUpProofFile
{
    public function __construct(
        public string $contents,
        public string $mimeType,
    ) {}

    public function __debugInfo(): array
    {
        return ['mimeType' => $this->mimeType, 'size' => strlen($this->contents)];
    }
}
