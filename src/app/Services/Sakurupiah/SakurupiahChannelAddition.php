<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahChannelAddition
{
    public ?SakurupiahFee $additionalFee;

    public ?string $kind;

    public int|float|null $defaultExpiredHours;

    public ?string $settlement;

    public function __construct(
        ?SakurupiahFee $additionalFee = null,
        ?string $kind = null,
        int|float|null $defaultExpiredHours = null,
        ?string $settlement = null,
    ) {
        if ($defaultExpiredHours !== null && (! is_finite((float) $defaultExpiredHours) || $defaultExpiredHours <= 0)) {
            throw new InvalidArgumentException('Sakurupiah channel expiry must be positive.');
        }

        $this->additionalFee = $additionalFee;
        $this->kind = $kind;
        $this->defaultExpiredHours = $defaultExpiredHours;
        $this->settlement = $settlement;
    }

    public function additionalFee(): ?SakurupiahFee
    {
        return $this->additionalFee;
    }

    public function kind(): ?string
    {
        return $this->kind;
    }

    public function defaultExpiredHours(): int|float|null
    {
        return $this->defaultExpiredHours;
    }

    public function settlement(): ?string
    {
        return $this->settlement;
    }
}
