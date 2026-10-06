<?php

namespace App\Enums;

/**
 * How one Customer-exclusive USDT deposit receiver entered the registry:
 * registered by an authenticated Owner or provisioned by an approved custody
 * provisioning adapter. Neither path stores or derives private key material.
 */
enum UsdtDepositReceiverSource: string
{
    case Owner = 'owner';
    case Provisioner = 'provisioner';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner registration',
            self::Provisioner => 'Custody provisioner',
        };
    }
}
