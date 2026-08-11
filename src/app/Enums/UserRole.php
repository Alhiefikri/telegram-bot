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

    public function canManageStoreOperations(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canViewOrdersAndPayments(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Finance, self::Support], true);
    }

    public function canReconcilePayments(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Finance], true);
    }

    public function canManagePaymentSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canSwitchPaymentProvider(): bool
    {
        return $this === self::Owner;
    }

    public function canBroadcast(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canResendReceipts(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Finance, self::Support], true);
    }

    public function canCompleteManualOrders(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Finance], true);
    }

    public function canViewRawPaymentPayloads(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Finance], true);
    }
}
