<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Finance = 'finance';
    case Support = 'support';

    public function canMutateProducts(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
