<?php

namespace App\Services\Sakurupiah;

enum SakurupiahTransactionStatus: string
{
    case Pending = 'pending';
    case Successful = 'berhasil';
    case Expired = 'expired';

    public static function fromProviderValue(mixed $value): self
    {
        if (! is_string($value)) {
            throw new SakurupiahSchemaException;
        }

        $status = self::tryFrom($value);
        if ($status === null) {
            throw new SakurupiahSchemaException;
        }

        return $status;
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Successful;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }
}
