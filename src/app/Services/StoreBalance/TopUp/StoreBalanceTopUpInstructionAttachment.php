<?php

namespace App\Services\StoreBalance\TopUp;

use InvalidArgumentException;

final readonly class StoreBalanceTopUpInstructionAttachment
{
    public function __construct(private string $photoContents)
    {
        if ($photoContents === '') {
            throw new InvalidArgumentException('Store Balance top-up instruction attachment is empty.');
        }
    }

    public function photoContents(): string
    {
        return $this->photoContents;
    }
}
