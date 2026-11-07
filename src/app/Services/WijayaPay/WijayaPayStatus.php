<?php

namespace App\Services\WijayaPay;

enum WijayaPayStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';

    public static function fromProviderValue(mixed $value): self
    {
        if (! is_string($value)) {
            throw new WijayaPaySchemaException;
        }

        return match (strtolower(trim($value))) {
            'pending' => self::Pending,
            'paid' => self::Paid,
            'expired' => self::Expired,
            default => throw new WijayaPaySchemaException,
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }
}
